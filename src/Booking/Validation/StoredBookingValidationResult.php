<?php
declare(strict_types=1);
namespace GeoFort\Booking\Validation;

final readonly class StoredBookingValidationResult
{
    /** @param list<StoredBookingIssue> $issues */
    public function __construct(public array $issues) {}
    public function isValid(): bool { return $this->issues === []; }
    public function hasCode(string $code): bool { foreach ($this->issues as $issue) if ($issue->code === $code) return true; return false; }
}
