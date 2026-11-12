<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use Google\AccessToken\Verify;
use GoogleBusinessProfile\Client;
use GoogleBusinessProfile\PubSub\AuthenticationException;
use GoogleBusinessProfile\PubSub\PushReceiver;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

require dirname(__DIR__) . '/vendor/autoload.php';

function expectValue(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function expectException(string $expected, Closure $action): void
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

$opensslConfig = tempnam(sys_get_temp_dir(), 'pubsub-test-');
if ($opensslConfig === false) {
    throw new RuntimeException('Could not create a temporary OpenSSL configuration.');
}
try {
    file_put_contents($opensslConfig, "[req]\n");
    $key = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'private_key_bits' => 2048,
        'config' => $opensslConfig,
    ]);
} finally {
    unlink($opensslConfig);
}
if ($key === false) {
    throw new RuntimeException('Could not generate the Pub/Sub test signing key.');
}
$details = openssl_pkey_get_details($key);
$jwks = json_encode(['keys' => [[
    'kty' => 'RSA',
    'kid' => 'test-key',
    'alg' => 'RS256',
    'n' => JWT::urlsafeB64Encode($details['rsa']['n']),
    'e' => JWT::urlsafeB64Encode($details['rsa']['e']),
]]], JSON_THROW_ON_ERROR);
$mock = new MockHandler([
    new Response(200, ['Content-Type' => 'application/json'], $jwks),
    new Response(200, ['Content-Type' => 'application/json'], '{"starRating":"FIVE","comment":"Дякую!"}'),
]);
$history = [];
$stack = HandlerStack::create($mock);
$stack->push(Middleware::history($history));
$http = new HttpClient(['handler' => $stack]);
$sdk = new Client($http, static fn () => 'business-profile-test-token');

$audience = 'https://example.test/api/webhooks/google-business-profile';
$serviceAccount = 'gbp-push@example-project.iam.gserviceaccount.com';
$subscription = 'projects/example-project/subscriptions/gbp-reviews';
$receiver = new PushReceiver($audience, $serviceAccount, $subscription, new Verify($http));
$claims = [
    'iss' => 'https://accounts.google.com',
    'aud' => $audience,
    'email' => $serviceAccount,
    'email_verified' => true,
    'iat' => time() - 5,
    'exp' => time() + 300,
];
$authorize = static fn (array $overrides = []) => 'Bearer ' . JWT::encode(
    array_replace($claims, $overrides),
    $key,
    'RS256',
    'test-key',
);

$account = 'accounts/123';
$shortName = 'locations/456/reviews/789';
$reviewName = $account . '/' . $shortName;
$event = ['type' => 'NEW_REVIEW', 'location' => 'locations/456', 'review' => $shortName];
$wrapped = [
    'subscription' => $subscription,
    'message' => [
        'messageId' => '4567890123456789',
        'data' => base64_encode(json_encode($event, JSON_THROW_ON_ERROR)),
    ],
];
$body = json_encode($wrapped, JSON_THROW_ON_ERROR);
$notification = $receiver->receive($authorize(), $body);
expectValue('NEW_REVIEW', $notification->type);
expectValue('4567890123456789', $notification->messageId);
expectValue($subscription, $notification->subscription);
expectValue($event, $notification->data);
expectValue($reviewName, $notification->reviewName($account));
$review = $sdk->myBusiness()->reviewsGet($notification->reviewName($account));
expectValue(['starRating' => 'FIVE', 'comment' => 'Дякую!'], $review);
expectValue(Verify::FEDERATED_SIGNON_CERT_URL, (string) $history[0]['request']->getUri());
expectValue('/v4/' . $reviewName, $history[1]['request']->getUri()->getPath());
expectValue('Bearer business-profile-test-token', $history[1]['request']->getHeaderLine('Authorization'));

$attributeBody = json_encode([
    'subscription' => $subscription,
    'message' => [
        'message_id' => 'second-message',
        'attributes' => ['notificationType' => 'UPDATED_REVIEW', 'reviewName' => $reviewName],
    ],
], JSON_THROW_ON_ERROR);
$updated = $receiver->receive($authorize(), $attributeBody);
expectValue('UPDATED_REVIEW', $updated->type);
expectValue('second-message', $updated->messageId);
expectValue($reviewName, $updated->reviewName($account));
expectException(InvalidArgumentException::class, static fn () => $updated->reviewName('accounts/999'));

foreach ([
    ['aud' => 'wrong-audience'],
    ['iss' => 'https://example.test'],
    ['email' => 'different@example.test'],
    ['email_verified' => false],
    ['exp' => time() - 60],
    ['exp' => null],
] as $overrides) {
    expectException(AuthenticationException::class, static fn () => $receiver->receive($authorize($overrides), $body));
}
$forgedParts = explode('.', $authorize());
$signature = JWT::urlsafeB64Decode($forgedParts[2]);
$signature[0] = $signature[0] ^ "\x01";
$forgedParts[2] = JWT::urlsafeB64Encode($signature);
$forged = implode('.', $forgedParts);
expectException(AuthenticationException::class, static fn () => $receiver->receive($forged, $body));
expectException(AuthenticationException::class, static fn () => $receiver->receive(null, $body));
expectException(AuthenticationException::class, static fn () => $receiver->receive(
    $authorize(),
    json_encode(array_replace($wrapped, ['subscription' => 'projects/other/subscriptions/other']), JSON_THROW_ON_ERROR),
));
expectException(InvalidArgumentException::class, static fn () => $receiver->receive($authorize(), '{"subscription":'));
$wrapped['message']['data'] = '%%%invalid-base64%%%';
expectException(InvalidArgumentException::class, static fn () => $receiver->receive(
    $authorize(),
    json_encode($wrapped, JSON_THROW_ON_ERROR),
));

echo "Authenticated Pub/Sub push and review lookup checks passed.\n";
