<?php

declare(strict_types=1);

namespace GeoFort\Booking\Cjp;

final readonly class BookingCjpIssue
{
    /** @param array<string,bool|int|string|null> $metadata */
    public function __construct(
        public string $code,
        public string $field,
        public string $title,
        public string $description,
        public string $category = 'VALIDATION',
        public string $severity = 'error',
        public array $metadata = [],
    ) {}
}
