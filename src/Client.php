<?php

declare(strict_types=1);

namespace GoogleBusinessProfile;

use Closure;
use Google\Client as GoogleClient;
use GuzzleHttp\ClientInterface;
use RuntimeException;

final class Client extends ApiServices
{
    public const SCOPE = 'https://www.googleapis.com/auth/business.manage';

    /** @param (Closure(): string)|null $accessToken */
    public function __construct(ClientInterface $http, ?Closure $accessToken = null)
    {
        parent::__construct(new Transport($http, $accessToken));
    }

    public static function fromGoogleClient(GoogleClient $google): self
    {
        return new self($google->getHttpClient(), static function () use ($google): string {
            $token = $google->getAccessToken();
            if ($google->isAccessTokenExpired() && !empty($token['refresh_token'])) {
                $token = $google->fetchAccessTokenWithRefreshToken();
            }
            if (empty($token['access_token']) || $google->isAccessTokenExpired()) {
                throw new RuntimeException('No valid Google OAuth access token. Reconnect the Google account.');
            }

            return $token['access_token'];
        });
    }
}
