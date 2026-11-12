<?php

declare(strict_types=1);

namespace GoogleBusinessProfile\Examples\PlainPhp;

use Google\Client as GoogleClient;
use GoogleBusinessProfile\Client;
use GoogleBusinessProfile\PubSub\AuthenticationException;
use GoogleBusinessProfile\PubSub\Notification;
use GoogleBusinessProfile\PubSub\PullReceiver;
use GoogleBusinessProfile\PubSub\PushReceiver;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

$projectAutoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once is_file($projectAutoload) ? $projectAutoload : dirname(__DIR__, 4) . '/autoload.php';

function requiredEnv(string $name): string
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        throw new RuntimeException("Set the {$name} environment variable.");
    }

    return $value;
}

function businessProfileClient(?ClientInterface $http = null): Client
{
    $google = new GoogleClient();
    $google->setClientId(requiredEnv('GBP_OAUTH_CLIENT_ID'));
    $google->setClientSecret(requiredEnv('GBP_OAUTH_CLIENT_SECRET'));
    $google->setScopes([Client::SCOPE]);
    $google->setHttpClient($http ?? new HttpClient(['connect_timeout' => 3, 'timeout' => 10]));
    $google->setAccessToken([
        'access_token' => '',
        'refresh_token' => requiredEnv('GBP_OAUTH_REFRESH_TOKEN'),
    ]);

    return Client::fromGoogleClient($google);
}

function newReview(Notification $notification, Client $sdk, string $account): ?array
{
    if ($notification->type !== 'NEW_REVIEW') {
        return null;
    }

    $reviewName = $notification->reviewName($account);
    $review = $sdk->myBusiness()->reviewsGet($reviewName);

    return [
        'message_id' => $notification->messageId,
        'review_name' => $reviewName,
        'star_rating' => $review['starRating'] ?? null,
    ];
}

function handlePush(
    PushReceiver $receiver,
    Client $sdk,
    string $account,
    ?string $authorization,
    string $body,
    callable $onReview,
): int {
    try {
        $notification = $receiver->receive($authorization, $body);
        $review = newReview($notification, $sdk, $account);
        if ($review !== null) {
            $onReview($review);
        }
    } catch (AuthenticationException) {
        return 401;
    } catch (InvalidArgumentException) {
        return 400;
    }

    return 204;
}

function handlePull(
    PullReceiver $receiver,
    Client $sdk,
    string $account,
    callable $onReview,
    callable $onFailure,
): int {
    $failed = false;

    foreach ($receiver->pull(5) as $delivery) {
        try {
            $review = newReview($delivery->notification(), $sdk, $account);
            if ($review !== null) {
                $onReview($review);
            }
            $receiver->acknowledge($delivery);
        } catch (Throwable $error) {
            $failed = true;
            $onFailure($error);
        }
    }

    return $failed ? 1 : 0;
}
