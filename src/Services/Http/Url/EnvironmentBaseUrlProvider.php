<?php
declare(strict_types=1);

namespace GeoFort\Services\Http\Url;

use GeoFort\Services\Http\Interfaces\BaseUrlProvider;
use InvalidArgumentException;

final class EnvironmentBaseUrlProvider implements BaseUrlProvider
{
    private const ALLOWED_BASE_URLS = [
        'development' => [
            'https://onderwijsformulier.test',
        ],
        'production' => [
            'https://onderwijsboeking.test.ignorelist.com',
            'https://onderwijsboeking.geofort.nl',
        ],
    ];

    private string $baseUrl;

    public function __construct(string $environment, string $baseUrl)
    {
        $environment = trim($environment);

        if (!array_key_exists($environment, self::ALLOWED_BASE_URLS)) {
            throw new InvalidArgumentException('Unsupported application environment for BASE_URL');
        }

        $baseUrl = trim($baseUrl);

        if ($baseUrl === '') {
            throw new InvalidArgumentException('BASE_URL must not be empty');
        }

        if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('BASE_URL must be a valid absolute URL');
        }

        $parts = parse_url($baseUrl);

        if (
            !is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || !isset($parts['host'])
            || $parts['host'] === ''
        ) {
            throw new InvalidArgumentException('BASE_URL must contain a supported scheme and host');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('BASE_URL must not contain user information');
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('BASE_URL must not contain a query string or fragment');
        }

        if (isset($parts['path']) && !in_array($parts['path'], ['', '/'], true)) {
            throw new InvalidArgumentException('BASE_URL must not contain an application subpath');
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $port = $parts['port'] ?? null;

        if ($environment === 'production' && $scheme !== 'https') {
            throw new InvalidArgumentException('Production BASE_URL must use HTTPS');
        }

        if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            $port = null;
        }

        $normalizedBaseUrl = $scheme . '://' . $host . ($port === null ? '' : ':' . $port);

        if (!in_array($normalizedBaseUrl, self::ALLOWED_BASE_URLS[$environment], true)) {
            throw new InvalidArgumentException('BASE_URL is not allowed for the application environment');
        }

        $this->baseUrl = $normalizedBaseUrl;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }
}
