<?php

declare(strict_types=1);

use GoogleBusinessProfile\PubSub\PushReceiver;

use function GoogleBusinessProfile\Examples\PlainPhp\businessProfileClient;
use function GoogleBusinessProfile\Examples\PlainPhp\handlePush;
use function GoogleBusinessProfile\Examples\PlainPhp\requiredEnv;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    return;
}

require_once dirname(__DIR__) . '/bootstrap.php';

try {
    $receiver = new PushReceiver(
        requiredEnv('GBP_PUSH_AUDIENCE'),
        requiredEnv('GBP_PUSH_SERVICE_ACCOUNT'),
        requiredEnv('GBP_PUBSUB_PUSH_SUBSCRIPTION'),
    );
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
    $body = file_get_contents('php://input');
    if ($body === false) {
        http_response_code(400);
        return;
    }
    http_response_code(handlePush(
        $receiver,
        businessProfileClient(),
        requiredEnv('GBP_ACCOUNT'),
        $authorization,
        $body,
        static function (array $review): void {
            if (!error_log('New Google Business Profile review ' . json_encode($review, JSON_THROW_ON_ERROR))) {
                throw new RuntimeException('Could not log the review.');
            }
        },
    ));
} catch (Throwable $error) {
    error_log('Google Business Profile push failed (' . $error::class . ').');
    http_response_code(500);
}
