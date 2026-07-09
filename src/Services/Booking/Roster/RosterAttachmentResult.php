<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\Roster;

final readonly class RosterAttachmentResult
{
    public function __construct(
        public bool $found,
        public ?string $path,
        public string $filename,
        public ?string $reason,
    ) {}
}
