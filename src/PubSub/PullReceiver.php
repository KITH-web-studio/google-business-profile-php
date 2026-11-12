<?php

declare(strict_types=1);

namespace GoogleBusinessProfile\PubSub;

use Closure;
use Google\Client as GoogleClient;
use GoogleBusinessProfile\Transport;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use UnexpectedValueException;

final class PullReceiver
{
    public const SCOPE = 'https://www.googleapis.com/auth/pubsub';

    private readonly Transport $transport;

    /** @param (Closure(): string)|null $accessToken */
    public function __construct(
        private readonly string $subscription,
        ClientInterface $http,
        ?Closure $accessToken = null,
    ) {
        if (preg_match('~\Aprojects/[^/\s]+/subscriptions/[^/\s]+\z~', $subscription) !== 1) {
            throw new InvalidArgumentException('Expected projects/{project}/subscriptions/{subscription}.');
        }

        $this->transport = new Transport($http, $accessToken);
    }

    public static function fromApplicationDefaultCredentials(string $subscription, ?ClientInterface $http = null): self
    {
        $google = new GoogleClient();
        $google->useApplicationDefaultCredentials();
        $google->setScopes([self::SCOPE]);
        $google->setHttpClient($http ?? new HttpClient(['connect_timeout' => 5, 'timeout' => 100]));

        return new self($subscription, $google->authorize());
    }

    /**
     * Pulls one batch. Decode and process each delivery before acknowledging it.
     *
     * @return list<PullMessage>
     */
    public function pull(int $maxMessages = 10): array
    {
        if ($maxMessages < 1 || $maxMessages > 2147483647) {
            throw new InvalidArgumentException('maxMessages must be a positive int32.');
        }

        $response = $this->request('pull', ['maxMessages' => $maxMessages]);
        $receivedMessages = $response['receivedMessages'] ?? [];
        if (!is_array($receivedMessages) || !array_is_list($receivedMessages)) {
            throw new UnexpectedValueException('Invalid Pub/Sub pull response.');
        }

        $deliveries = [];
        foreach ($receivedMessages as $received) {
            $ackId = $received['ackId'] ?? null;
            $message = $received['message'] ?? null;
            $attempt = $received['deliveryAttempt'] ?? null;
            if (!is_string($ackId) || $ackId === '' || !is_array($message) || ($attempt !== null && !is_int($attempt))) {
                throw new UnexpectedValueException('Invalid delivery in Pub/Sub pull response.');
            }
            $deliveries[] = new PullMessage($this->subscription, $ackId, $attempt, $message);
        }

        return $deliveries;
    }

    public function acknowledge(PullMessage $message): void
    {
        $this->request('acknowledge', ['ackIds' => [$this->ackId($message)]]);
    }

    /** A deadline of zero negatively acknowledges the delivery. */
    public function modifyAckDeadline(PullMessage $message, int $seconds): void
    {
        if ($seconds < 0 || $seconds > 600) {
            throw new InvalidArgumentException('The acknowledgement deadline must be between 0 and 600 seconds.');
        }

        $this->request('modifyAckDeadline', [
            'ackIds' => [$this->ackId($message)],
            'ackDeadlineSeconds' => $seconds,
        ]);
    }

    private function ackId(PullMessage $message): string
    {
        if ($message->subscription !== $this->subscription || $message->ackId === '') {
            throw new InvalidArgumentException('Expected a delivery from this Pub/Sub subscription.');
        }

        return $message->ackId;
    }

    private function request(string $operation, array $body): array
    {
        return $this->transport->json(
            'pubsub.googleapis.com',
            'POST',
            'v1/{+subscription}:' . $operation,
            ['subscription' => $this->subscription],
            [],
            $body,
        );
    }
}
