<?php
declare(strict_types=1);
namespace GeoFort\Services\Http\BaseUrlProvider;

interface BaseUrlProvider {
    public  function getBaseUrl(): string;
}
?>