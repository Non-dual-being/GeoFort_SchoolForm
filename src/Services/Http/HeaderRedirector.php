<?php
declare(strict_types=1);
namespace GeoFort\Serices\Http;
use GeoFort\Services\Http\Interfaces\Redictor;


final class HeaderRedictor implements Redictor {
    public function __construct(private  readonly GlobalBaseUrlProvider $baseUrlProvider )
    {

    }

    public function to(string $path, array $query = [], int $httpCode = 303): never {
        $base = $this->baseUrlProvider->getBaseUrl();
        $rel = '/' . ltrim($path, '/');
        $qr = $query
            ? ('?' . http_build_query($query))
            : '';
        header('Location: ' . $base . $rel . $qs, true, $httpCode);
        exit();
    }
}
?>