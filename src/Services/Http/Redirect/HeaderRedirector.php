<?php
declare(strict_types=1);
namespace GeoFort\Services\Http\Redirect;

use GeoFort\Services\Http\Interfaces\Redirector;
use GeoFort\Services\Http\Url\EnvironmentBaseUrlProvider;

final class HeaderRedirector implements Redirector {
    public function __construct(private  readonly EnvironmentBaseUrlProvider $baseUrlProvider )
    {

    }

    public function to(string $path, array $query = [], int $httpCode = 303): never {
        $base = $this->baseUrlProvider->getBaseUrl();
        $rel = '/' . ltrim($path, '/');
        $qs = $query
            ? ('?' . http_build_query($query))
            : '';
        header('Location: ' . $base . $rel . $qs, true, $httpCode);
        exit();
    }
}
?>
