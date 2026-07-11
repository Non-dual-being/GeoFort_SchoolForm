<?php
declare(strict_types=1);

namespace GeoFort\Services\Http\Redirect;

use GeoFort\Services\Http\Interfaces\BaseUrlProvider;
use GeoFort\Services\Http\Interfaces\Redirector;
use InvalidArgumentException;

final class HeaderRedirector implements Redirector
{
    private const ALLOWED_REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    public function __construct(private readonly BaseUrlProvider $baseUrlProvider)
    {
    }

    /**
     * @param array<string, scalar|null> $query
     */
    public function to(string $path, array $query = [], int $httpCode = 303): never
    {
        $this->assertValidInternalPath($path);
        $this->assertValidRedirectStatus($httpCode);

        $base = $this->baseUrlProvider->getBaseUrl();
        $queryString = $query
            ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986)
            : '';

        header('Location: ' . $base . $path . $queryString, true, $httpCode);
        exit;
    }

    private function assertValidInternalPath(string $path): void
    {
        if (
            $path === ''
            || str_contains($path, "\r")
            || str_contains($path, "\n")
            || str_starts_with($path, '//')
            || parse_url($path, PHP_URL_SCHEME) !== null
            || !str_starts_with($path, '/')
        ) {
            throw new InvalidArgumentException('Only internal application paths are allowed');
        }
    }

    private function assertValidRedirectStatus(int $httpCode): void
    {
        if (!in_array($httpCode, self::ALLOWED_REDIRECT_STATUSES, true)) {
            throw new InvalidArgumentException('Unsupported HTTP redirect status code');
        }
    }
}
