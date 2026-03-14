<?php
declare(strict_types=1);
namespace GeoFort\Services\Http\Interfaces;

interface Redirector
{
    public function to(
        string $path,
        array $query = [],
        int $httpCode = 303
    ): never;
}
?>