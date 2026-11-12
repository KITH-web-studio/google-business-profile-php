<?php

declare(strict_types=1);

namespace GoogleBusinessProfile\PubSub;

use InvalidArgumentException;
use JsonException;

/** @internal */
final class MessageDecoder
{
    public static function notification(string $subscription, array $message): Notification
    {
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
            $data = self::jsonObject($decoded);
        }

        $type = $data['type'] ?? $data['notification_type'] ?? $data['notificationType']
            ?? $attributes['type'] ?? $attributes['notification_type'] ?? $attributes['notificationType'] ?? null;
        if (!is_string($type) || trim($type) === '') {
            throw new InvalidArgumentException('The Business Profile notification type is missing.');
        }

        return new Notification($type, $messageId, $subscription, $data, $attributes);
    }

    public static function jsonObject(string $json): array
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
