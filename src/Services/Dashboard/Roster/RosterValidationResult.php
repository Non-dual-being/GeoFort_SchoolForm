<?php

declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Roster;

final class RosterValidationResult
{
    /** @var list<array<string,mixed>> */
    public array $errors = [];
    /** @var list<array<string,mixed>> */
    public array $warnings = [];

    public function __construct(public readonly int $planId, public readonly int $revision) {}

    /** @param array<string,mixed> $context */
    public function error(string $code, string $message, array $context = []): void
    {
        $this->errors[] = ['code' => $code, 'severity' => 'error', 'message' => $message, ...$context];
    }

    /** @param array<string,mixed> $context */
    public function warning(string $code, string $message, array $context = []): void
    {
        $this->warnings[] = ['code' => $code, 'severity' => 'warning', 'message' => $message, ...$context];
    }

    public function isValid(): bool { return $this->errors === []; }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['planId' => $this->planId, 'revision' => $this->revision, 'valid' => $this->isValid(),
            'errors' => $this->errors, 'warnings' => $this->warnings];
    }
}
