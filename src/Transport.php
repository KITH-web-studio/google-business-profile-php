<?php

declare(strict_types=1);

namespace GoogleBusinessProfile;

use Closure;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use UnexpectedValueException;

/** @internal */
final class Transport
{
    /** @param (Closure(): string)|null $accessToken */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly ?Closure $accessToken,
    ) {
    }

    public function json(
        string $host,
        string $method,
        string $path,
        array $parameters,
        array $query,
        array|object|null $body = null,
    ): array {
        $options = $body === null ? [] : ['json' => $body === [] ? new \stdClass() : $body];

        return $this->send($host, $method, $path, $parameters, $query, $options);
    }

    public function upload(string $resourceName, mixed $data, string $contentType): array
    {
        if (!is_string($data) && !is_resource($data) && !$data instanceof StreamInterface) {
            throw new InvalidArgumentException('Media must be a binary string, an open stream or a PSR-7 stream.');
        }

        return $this->send(
            'mybusiness.googleapis.com',
            'POST',
            'upload/v1/media/{+resourceName}',
            ['resourceName' => $resourceName],
            ['upload_type' => 'media'],
            ['body' => $data, 'headers' => ['Content-Type' => $contentType]],
        );
    }

    private function send(
        string $host,
        string $method,
        string $path,
        array $parameters,
        array $query,
        array $options,
    ): array {
        $path = preg_replace_callback('/\{(\+?)([\w.]+)\}/', static function (array $token) use ($parameters): string {
            $value = $parameters[$token[2]];
            $segments = $token[1] === '+' ? explode('/', $value) : [$value];

            foreach ($segments as $segment) {
                if ($segment === '' || $segment === '.' || $segment === '..' || preg_match('/[\x00-\x1f\x7f]/', $segment)) {
                    throw new InvalidArgumentException('Invalid Google resource name: ' . $token[2]);
                }
            }

            return implode('/', array_map('rawurlencode', $segments));
        }, $path);

        $options['headers']['Accept'] = 'application/json';
        if ($this->accessToken !== null) {
            $options['headers']['Authorization'] = 'Bearer ' . ($this->accessToken)();
            $options['auth'] = null;
        }
        $options['query'] = $this->encodeQuery($query);
        $options['allow_redirects'] = false;
        $options['http_errors'] = true;

        $response = $this->http->request($method, 'https://' . $host . '/' . $path, $options);
        $body = (string) $response->getBody();
        if ($body === '') {
            return [];
        }

        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        if (!is_array($decoded)) {
            throw new UnexpectedValueException('Google returned an unexpected JSON response.');
        }

        return $decoded;
    }

    private function encodeQuery(array $query): string
    {
        $pairs = [];
        foreach ($query as $name => $values) {
            $values = is_array($values) ? $values : [$values];
            if (!array_is_list($values)) {
                throw new InvalidArgumentException('Use dotted query names for nested Google parameters.');
            }
            foreach ($values as $value) {
                if ($value === null) {
                    continue;
                }
                if (!is_scalar($value)) {
                    throw new InvalidArgumentException('Query parameters must be scalars or lists of scalars.');
                }
                $value = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
                $pairs[] = rawurlencode((string) $name) . '=' . rawurlencode($value);
            }
        }

        return implode('&', $pairs);
    }
}
