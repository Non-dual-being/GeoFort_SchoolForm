<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\Submission;

final readonly class BookingSubmissionResult
{
    public function __construct(public bool $mailSent) {}
}
