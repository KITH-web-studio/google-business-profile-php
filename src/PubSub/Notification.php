<?php

declare(strict_types=1);

namespace GoogleBusinessProfile\PubSub;

use InvalidArgumentException;

final class Notification
{
    public function __construct(
        public readonly string $type,
        public readonly string $messageId,
        public readonly string $subscription,
        public readonly array $data,
        public readonly array $attributes,
    ) {
    }

    /**
     * Returns the v4 review resource name and checks it belongs to the supplied account.
     */
    public function reviewName(string $accountName): string
    {
        if (preg_match('~\Aaccounts/[A-Za-z0-9_-]+\z~', $accountName) !== 1) {
            throw new InvalidArgumentException('Expected an account name in the form accounts/{accountId}.');
        }

        $review = $this->data['review'] ?? $this->data['review_name'] ?? $this->data['reviewName']
            ?? $this->attributes['review'] ?? $this->attributes['review_name'] ?? $this->attributes['reviewName'] ?? null;

        if (!is_string($review)
            || preg_match('~\A(?:(accounts/[A-Za-z0-9_-]+)/)?(locations/[A-Za-z0-9_-]+/reviews/[A-Za-z0-9_-]+)\z~', $review, $parts) !== 1
        ) {
            throw new InvalidArgumentException('The notification has no valid review resource name.');
        }

        if ($parts[1] !== '' && $parts[1] !== $accountName) {
            throw new InvalidArgumentException('The review belongs to a different Business Profile account.');
        }

        return $accountName . '/' . $parts[2];
    }
}
