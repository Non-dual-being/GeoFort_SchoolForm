<?php
declare(strict_types=1);
namespace GeoFort\Booking\Validation;

final readonly class StoredBookingIssue
{
    public function __construct(public string $code, public StoredBookingIssueCategory $category, public string $field) {}
}
