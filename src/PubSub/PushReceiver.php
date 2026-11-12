<?php

declare(strict_types=1);

namespace GoogleBusinessProfile\PubSub;

use Google\AccessToken\Verify;
use GuzzleHttp\Client as HttpClient;
use InvalidArgumentException;
use JsonException;
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

        $envelope = $this->decodeObject($body);
        if (($envelope['subscription'] ?? null) !== $this->subscription) {
            throw new AuthenticationException('Unexpected Pub/Sub subscription.');
        }

        $message = $envelope['message'] ?? null;
        if (!is_array($message)) {
            throw new InvalidArgumentException('Expected a wrapped Pub/Sub message.');
        }

        $messageId = $message['messageId'] ?? $message['message_id'] ?? null;
        if (!is_string($messageId) || $messageId === '') {
            throw new InvalidArgumentException('The Pub/Sub message ID is missing.');
        }

        $attributes = $message['attributes'] ?? [];
        if (!is_array($attributes)) {
            throw new InvalidArgumentException('Invalid Pub/Sub message attributes.');
        }

        $data = [];
        $encoded = $message['data'] ?? '';
        if ($encoded !== '') {
            $decoded = is_string($encoded) ? base64_decode($encoded, true) : false;
            if ($decoded === false) {
                throw new InvalidArgumentException('Invalid Pub/Sub message encoding.');
            }
            $data = $this->decodeObject($decoded);
        }

        $type = $data['type'] ?? $data['notification_type'] ?? $data['notificationType']
            ?? $attributes['type'] ?? $attributes['notification_type'] ?? $attributes['notificationType'] ?? null;
        if (!is_string($type) || trim($type) === '') {
            throw new InvalidArgumentException('The Business Profile notification type is missing.');
        }

        return new Notification($type, $messageId, $this->subscription, $data, $attributes);
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

    private function decodeObject(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Invalid Pub/Sub JSON.', previous: $error);
        }

        if (!is_array($decoded) || !str_starts_with(ltrim($json), '{')) {
            throw new InvalidArgumentException('Expected a JSON object in the Pub/Sub notification.');
        }

        return $decoded;
    }
}
