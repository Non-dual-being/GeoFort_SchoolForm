<?php
declare(strict_types=1);
namespace GeoFort\Services\Http;

final class IpResult {
    public function __construct (
        public readonly ?string $ip,
        public readonly ?string $error,
        public readonly bool $hasError
    ){}
}
?>