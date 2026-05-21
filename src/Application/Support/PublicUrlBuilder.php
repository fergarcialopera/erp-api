<?php

declare(strict_types=1);

namespace App\Application\Support;

final class PublicUrlBuilder
{
    public function __construct(private readonly string $publicBaseUrl)
    {
    }

    public function asset(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return rtrim($this->publicBaseUrl, '/') . '/' . ltrim($path, '/');
    }
}
