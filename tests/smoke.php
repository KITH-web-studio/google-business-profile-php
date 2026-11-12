<?php

declare(strict_types=1);

use Google\Client as GoogleClient;
use GoogleBusinessProfile\Client;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

function check(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$mock = new MockHandler([
    new Response(200, [], '{"accounts":[{"name":"accounts/123"}]}'),
    new Response(200, [], '{"locations":[{"name":"locations/456"}]}'),
    new Response(200, [], '{"reviews":[],"nextPageToken":"next"}'),
    new Response(200, [], '{"comment":"Спасибо!"}'),
    new Response(200, [], '{"multiDailyMetricTimeSeries":[]}'),
    new Response(200, [], '{"resourceName":"uploads/data"}'),
    new Response(204),
    new Response(200, [], '{"editState":"PENDING"}'),
    new Response(429, [], '{"error":{"status":"RESOURCE_EXHAUSTED"}}'),
]);
$history = [];
$handler = HandlerStack::create($mock);
$handler->push(Middleware::history($history));
$http = new HttpClient(['handler' => $handler]);

$google = new GoogleClient();
$google->setHttpClient($http);
$google->setAccessToken(['access_token' => 'test-token', 'expires_in' => 3600, 'created' => time()]);
$sdk = Client::fromGoogleClient($google);

check(['accounts' => [['name' => 'accounts/123']]], $sdk->accountManagement()->accountsList());
check(['locations' => [['name' => 'locations/456']]], $sdk->businessInformation()->accountsLocationsList(
    'accounts/123',
    ['readMask' => 'name,title'],
));
$legacy = $sdk->myBusiness();
check('next', $legacy->reviewsList('accounts/123/locations/456', ['pageToken' => 'a+b'])['nextPageToken']);
$legacy->reviewsUpdateReply('accounts/123/locations/456/reviews/789', ['comment' => 'Спасибо!']);
$sdk->performance()->locationsFetchMultiDailyMetricsTimeSeries('locations/456', [
    'dailyMetrics' => ['CALL_CLICKS', 'WEBSITE_CLICKS'],
    'dailyRange.startDate.year' => 2026,
]);
check('uploads/data', $legacy->mediaStartUpload('accounts/123/locations/456')['resourceName']);
check([], $sdk->mediaUpload()->upload('uploads/data', "a\x00b"));
$sdk->businessInformationAlpha()->locationsAttributesGetExtended('locations/456/attributes');

try {
    $sdk->notifications()->accountsGetNotificationSetting('accounts/123/notificationSetting');
    throw new RuntimeException('Expected HTTP 429.');
} catch (ClientException $error) {
    check(429, $error->getResponse()->getStatusCode());
}

/** @var list<RequestInterface> $requests */
$requests = array_column($history, 'request');
check('Bearer test-token', $requests[0]->getHeaderLine('Authorization'));
check('https://mybusinessaccountmanagement.googleapis.com/v1/accounts', (string) $requests[0]->getUri());
check('/v1/accounts/123/locations', $requests[1]->getUri()->getPath());
check('readMask=name%2Ctitle', $requests[1]->getUri()->getQuery());
check('/v4/accounts/123/locations/456/reviews', $requests[2]->getUri()->getPath());
check('pageToken=a%2Bb', $requests[2]->getUri()->getQuery());
check('PUT', $requests[3]->getMethod());
check('/v4/accounts/123/locations/456/reviews/789/reply', $requests[3]->getUri()->getPath());
check(['comment' => 'Спасибо!'], json_decode((string) $requests[3]->getBody(), true, 512, JSON_THROW_ON_ERROR));
check('dailyMetrics=CALL_CLICKS&dailyMetrics=WEBSITE_CLICKS&dailyRange.startDate.year=2026', $requests[4]->getUri()->getQuery());
check('', (string) $requests[5]->getBody());
check('/upload/v1/media/uploads/data', $requests[6]->getUri()->getPath());
check('upload_type=media', $requests[6]->getUri()->getQuery());
check("a\x00b", (string) $requests[6]->getBody());
check('/v1alpha/locations/456/attributes:getExtended', $requests[7]->getUri()->getPath());

try {
    $legacy->reviewsList('accounts/../locations/456');
    throw new RuntimeException('Expected an invalid resource name error.');
} catch (InvalidArgumentException) {
    check(9, count($history));
}

$google->setClientId('example-client');
$google->setClientSecret('example-secret');
$google->setAccessToken([
    'access_token' => 'expired-token',
    'refresh_token' => 'refresh-token',
    'expires_in' => 3600,
    'created' => time() - 7200,
]);
$mock->append(
    new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"new-token","expires_in":3600}'),
    new Response(200, [], '{"accounts":[]}'),
    new Response(200, [], '{"accounts":[]}'),
);
$sdk->accountManagement()->accountsList();
$sdk->accountManagement()->accountsList();
check('oauth2.googleapis.com', $history[9]['request']->getUri()->getHost());
check('Bearer new-token', $history[10]['request']->getHeaderLine('Authorization'));
check('Bearer new-token', $history[11]['request']->getHeaderLine('Authorization'));
check('refresh-token', $google->getAccessToken()['refresh_token']);

echo "Google Business Profile SDK smoke checks passed.\n";
