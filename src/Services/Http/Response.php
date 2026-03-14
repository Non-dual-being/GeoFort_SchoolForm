<?php
declare(strict_types=1);

namespace GeoFort\Services\Http;

use GlobalBaseUrlProvider;

abstract class Response
{

    /** protected makes a private and mutable  */
    /** readonly only constructor is allowed to fill in the var */


    protected int $status = 200;

    /** @var array<string, string> */
    protected array $headers = [];

    /** @var array<mixed> */
    protected array $payload = [];

    public function __construct(
        protected readonly GlobalBaseUrlProvider $baseUrlProvider
    ) {}

    public function getBaseUrl(): string
    {
        return $this->baseUrlProvider->getBaseUrl();
    }

    public function header(string $key, string $value): static
    {
        $this->headers[$key] = $value;
        return $this;
    }

    abstract public function ok(): static;

    abstract public function validationError(array $fieldErrors): static;

    // We verplichten de backend-developer om een message mee te geven (voor interne logs)
    abstract public function serverError(string $message, int $code = 500, bool $logError = false): static;

    abstract public function send(): void;
}