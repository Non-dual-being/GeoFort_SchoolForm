<?php
declare(strict_types=1);
namespace GeoFort\Services\Http;

abstract class Response {
    public function __construct(
        protected int $status = 200,
        protected array $headers = []
    ) {}

    abstract public function send(): void;
}
?>