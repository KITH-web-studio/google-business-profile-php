<?php

declare(strict_types=1);

use GoogleBusinessProfile\PubSub\PullReceiver;

use function GoogleBusinessProfile\Examples\PlainPhp\businessProfileClient;
use function GoogleBusinessProfile\Examples\PlainPhp\handlePull;
use function GoogleBusinessProfile\Examples\PlainPhp\requiredEnv;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    return;
}

require_once __DIR__ . '/bootstrap.php';

try {
    exit(handlePull(
        PullReceiver::fromApplicationDefaultCredentials(requiredEnv('GBP_PUBSUB_PULL_SUBSCRIPTION')),
        businessProfileClient(),
        requiredEnv('GBP_ACCOUNT'),
        static function (array $review): void {
            $line = json_encode($review, JSON_THROW_ON_ERROR) . PHP_EOL;
            if (fwrite(STDOUT, $line) !== strlen($line)) {
                throw new RuntimeException('Could not write the review to stdout.');
            }
        },
        static function (Throwable $error): void {
            fwrite(STDERR, 'Pub/Sub delivery failed (' . $error::class . ').' . PHP_EOL);
        },
    ));
} catch (Throwable $error) {
    fwrite(STDERR, 'Pub/Sub pull failed (' . $error::class . '). Check the environment and credentials.' . PHP_EOL);
    exit(1);
}
