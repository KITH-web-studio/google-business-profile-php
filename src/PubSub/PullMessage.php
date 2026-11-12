<?php

declare(strict_types=1);

namespace GoogleBusinessProfile\PubSub;

final class PullMessage
{
    public function __construct(
        public readonly string $subscription,
        public readonly string $ackId,
        public readonly ?int $deliveryAttempt,
        private readonly array $message,
    ) {
    }

    /**
     * Decodes the Business Profile payload for this delivery.
     *
     * @throws \InvalidArgumentException
     */
    public function notification(): Notification
    {
        return MessageDecoder::notification($this->subscription, $this->message);
    }
}
