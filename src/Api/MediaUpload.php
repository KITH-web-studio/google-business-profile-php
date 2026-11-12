<?php

declare(strict_types=1);

namespace GoogleBusinessProfile\Api;

use GoogleBusinessProfile\Transport;

final class MediaUpload
{
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * @param string|resource|\Psr\Http\Message\StreamInterface $data
     * @see https://developers.google.com/my-business/content/upload-photos
     */
    public function upload(string $resourceName, mixed $data, string $contentType = 'application/octet-stream'): array
    {
        return $this->transport->upload($resourceName, $data, $contentType);
    }

    /** @see https://developers.google.com/my-business/reference/media/rest/v1/media/upload */
    public function uploadMetadata(string $resourceName, array|object $body = [], array $query = []): array
    {
        return $this->transport->json(
            'mybusiness.googleapis.com',
            'POST',
            'v1/media/{+resourceName}',
            ['resourceName' => $resourceName],
            $query,
            $body,
        );
    }
}
