<?php

declare(strict_types=1);

use GoogleBusinessProfile\Client;
use GoogleBusinessProfile\PubSub\PullReceiver;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

require dirname(__DIR__) . '/vendor/autoload.php';

function checkPull(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function checkPullException(string $expected, Closure $action): void
{
    try {
        $action();
    } catch (Throwable $error) {
        if ($error instanceof $expected) {
            return;
        }
        throw $error;
    }
    throw new RuntimeException('Expected ' . $expected);
}

$subscription = 'projects/example-project/subscriptions/gbp-reviews-pull';
$account = 'accounts/123';
$reviewName = $account . '/locations/456/reviews/789';
$event = [
    'ackId' => 'ack-new',
    'deliveryAttempt' => 2,
    'message' => [
        'messageId' => '001',
        'data' => base64_encode('{"type":"NEW_REVIEW","review":"locations/456/reviews/789"}'),
    ],
];
$malformed = [
    'ackId' => 'ack-bad',
    'message' => ['messageId' => '002', 'data' => '%invalid%'],
];
$ignored = [
    'ackId' => 'ack-update',
    'message' => ['message_id' => '003', 'attributes' => ['notificationType' => 'UPDATED_REVIEW']],
];

$mock = new MockHandler([
    new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"test-pubsub-token","expires_in":3600}'),
    new Response(200, [], json_encode(['receivedMessages' => [$event, $malformed, $ignored]], JSON_THROW_ON_ERROR)),
    new Response(200, [], '{"starRating":"FIVE"}'),
    new Response(200, [], '{}'),
    new Response(200, [], '{}'),
    new Response(503, [], '{"error":{"message":"temporary acknowledgement error"}}'),
    new Response(200, [], '{}'),
]);
$history = [];
$stack = HandlerStack::create($mock);
$stack->push(Middleware::history($history));
$http = new HttpClient(['handler' => $stack]);

$adcFile = tempnam(sys_get_temp_dir(), 'pubsub-adc-test-');
if ($adcFile === false) {
    throw new RuntimeException('Could not create a temporary ADC file.');
}
$previousAdc = getenv('GOOGLE_APPLICATION_CREDENTIALS');
try {
    file_put_contents($adcFile, json_encode([
        'type' => 'authorized_user',
        'client_id' => 'test-client-id',
        'client_secret' => 'test-client-secret',
        'refresh_token' => 'test-refresh-token',
    ], JSON_THROW_ON_ERROR));
    putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $adcFile);
    $receiver = PullReceiver::fromApplicationDefaultCredentials($subscription, $http);

    $sdk = new Client($http, static fn () => 'test-business-profile-token');
    [$newDelivery, $badDelivery, $ignoredDelivery] = $receiver->pull(5);
    checkPull('Bearer test-pubsub-token', $mock->getLastRequest()->getHeaderLine('Authorization'));
    checkPull(2, count($history));
    checkPull(2, $newDelivery->deliveryAttempt);
    $notification = $newDelivery->notification();
    checkPull('NEW_REVIEW', $notification->type);
    checkPull('001', $notification->messageId);
    checkPull($subscription, $notification->subscription);
    checkPull($reviewName, $notification->reviewName($account));
    checkPull(['starRating' => 'FIVE'], $sdk->myBusiness()->reviewsGet($notification->reviewName($account)));
    $receiver->acknowledge($newDelivery);
    checkPull('Bearer test-pubsub-token', $mock->getLastRequest()->getHeaderLine('Authorization'));

    checkPullException(InvalidArgumentException::class, static fn () => $badDelivery->notification());
    checkPull('UPDATED_REVIEW', $ignoredDelivery->notification()->type);
    checkPull(null, $ignoredDelivery->deliveryAttempt);
    checkPullException(InvalidArgumentException::class, static fn () => $receiver->pull(0));
    checkPullException(InvalidArgumentException::class, static fn () => $receiver->modifyAckDeadline($badDelivery, 601));
    $otherReceiver = new PullReceiver('projects/example-project/subscriptions/other', $http);
    checkPullException(InvalidArgumentException::class, static fn () => $otherReceiver->acknowledge($newDelivery));
    checkPull(4, count($history));

    $receiver->modifyAckDeadline($badDelivery, 0);
    checkPullException(ServerException::class, static fn () => $receiver->acknowledge($ignoredDelivery));
    checkPull([], $receiver->pull(1));
} finally {
    putenv($previousAdc === false ? 'GOOGLE_APPLICATION_CREDENTIALS' : 'GOOGLE_APPLICATION_CREDENTIALS=' . $previousAdc);
    unlink($adcFile);
}

$requests = array_column($history, 'request');
checkPull('oauth2.googleapis.com', $requests[0]->getUri()->getHost());
checkPull('https://pubsub.googleapis.com/v1/' . $subscription . ':pull', (string) $requests[1]->getUri());
checkPull(['maxMessages' => 5], json_decode((string) $requests[1]->getBody(), true, 512, JSON_THROW_ON_ERROR));
checkPull('/v4/' . $reviewName, $requests[2]->getUri()->getPath());
checkPull('Bearer test-business-profile-token', $requests[2]->getHeaderLine('Authorization'));
checkPull('/v1/' . $subscription . ':acknowledge', $requests[3]->getUri()->getPath());
checkPull(['ackIds' => ['ack-new']], json_decode((string) $requests[3]->getBody(), true, 512, JSON_THROW_ON_ERROR));
checkPull('/v1/' . $subscription . ':modifyAckDeadline', $requests[4]->getUri()->getPath());
checkPull(
    ['ackIds' => ['ack-bad'], 'ackDeadlineSeconds' => 0],
    json_decode((string) $requests[4]->getBody(), true, 512, JSON_THROW_ON_ERROR),
);
checkPull(7, count($requests));

echo "Pub/Sub pull, acknowledgement and redelivery checks passed.\n";
