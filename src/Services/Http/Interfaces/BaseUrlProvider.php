<?php
declare(strict_types=1);

namespace GeoFort\Services\Http\Interfaces;

interface BaseUrlProvider
{
    public function getBaseUrl(): string;
}
