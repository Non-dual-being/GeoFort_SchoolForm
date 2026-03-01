<?php
declare(strict_types=1);
namespace GeoFort\Serices\Http\Interfaces\Redictor;

Interface Redirector
{
    public function to(
        string $path,
        array $query = [],
        int $httpCode = 303
    ): never;
}
?>