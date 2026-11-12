<?php

declare(strict_types=1);

namespace GoogleBusinessProfile\PubSub;

use Google\AccessToken\Verify;
use GuzzleHttp\Client as HttpClient;
use InvalidArgumentException;
use UnexpectedValueException;

final class PushReceiver
{
    private readonly Verify $verifier;

    public function __construct(
        private readonly string $audience,
        private readonly string $serviceAccountEmail,
        private readonly string $subscription,
        ?Verify $verifier = null,
    ) {
        if (trim($audience) === '' || trim($serviceAccountEmail) === '' || trim($subscription) === '') {
            throw new InvalidArgumentException('Configure the Pub/Sub audience, service account email and subscription.');
        }

        $this->verifier = $verifier ?? new Verify(new HttpClient(['connect_timeout' => 2, 'timeout' => 5]));
    }

    /**
     * Authenticates a wrapped Pub/Sub push and decodes the Business Profile notification.
     *
     * @throws AuthenticationException
     * @throws InvalidArgumentException
     */
    public function receive(?string $authorization, string $body): Notification
    {
        $this->authenticate($authorization);

        $envelope = MessageDecoder::jsonObject($body);
        if (($envelope['subscription'] ?? null) !== $this->subscription) {
            throw new AuthenticationException('Unexpected Pub/Sub subscription.');
        }

        $message = $envelope['message'] ?? null;
        if (!is_array($message)) {
            throw new InvalidArgumentException('Expected a wrapped Pub/Sub message.');
        }

        return MessageDecoder::notification($this->subscription, $message);
    }

    private function authenticate(?string $authorization): void
    {
        if (preg_match('/\ABearer[ \t]+([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)\z/i', $authorization ?? '', $matches) !== 1) {
            throw new AuthenticationException('A Pub/Sub bearer token is required.');
        }

        try {
            $claims = $this->verifier->verifyIdToken($matches[1], $this->audience);
        } catch (UnexpectedValueException | InvalidArgumentException $error) {
            throw new AuthenticationException('Invalid Pub/Sub bearer token.', previous: $error);
        }

        if ($claims === false
            || ($claims['aud'] ?? null) !== $this->audience
            || ($claims['email'] ?? null) !== $this->serviceAccountEmail
            || !in_array($claims['email_verified'] ?? null, [true, 'true'], true)
            || !is_int($claims['exp'] ?? null)
            || $claims['exp'] <= time()
        ) {
            throw new AuthenticationException('Invalid Pub/Sub bearer token.');
        }
    }
}
