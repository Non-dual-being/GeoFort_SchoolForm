<?php
declare(strict_types=1);

namespace GeoFort\Booking\Validation;

use DateTimeImmutable;
use GeoFort\Booking\Stored\StoredBooking;
use GeoFort\Services\Sql\DisabledDatesSqlService;
use GeoFort\Validation\FieldValidationException;
use GeoFort\Validation\ProgramSelectionValidator;

final readonly class StoredBookingVisitDateValidator
{
    public function __construct(
        private DisabledDatesSqlService $disabledDates,
        private ProgramSelectionValidator $programValidator = new ProgramSelectionValidator(),
    ) {}

    public function validate(StoredBooking $booking, bool $confirmed, DateTimeImmutable $today): StoredBookingValidationResult
    {
        $dateValidation = $this->validateDate($booking, $confirmed, $today);
        if ($dateValidation->hasCode('INVALID_VISIT_DATE')) return $dateValidation;

        $issues = $dateValidation->issues;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $booking->visitDate, $today->getTimezone());
        if ($date === false) throw new \LogicException('Geldige bezoekdatum kon niet worden opgebouwd.');
        try {
            $this->programValidator->validate($booking->program, $booking->schoolSector, $date);
        } catch (FieldValidationException) {
            $issues[] = new StoredBookingIssue(
                'CURRENT_CONFIGURATION_MISMATCH',
                $booking->source->isLegacy()
                    ? StoredBookingIssueCategory::HistoricalConfiguration
                    : StoredBookingIssueCategory::Policy,
                'programma',
            );
        } catch (\InvalidArgumentException|\LogicException) {
            $issues[] = new StoredBookingIssue('INVALID_CONFIGURATION_KEY', StoredBookingIssueCategory::Structural, 'configuratie');
        }

        return new StoredBookingValidationResult($issues);
    }

    public function validateDate(StoredBooking $booking, bool $confirmed, DateTimeImmutable $today): StoredBookingValidationResult
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $booking->visitDate, $today->getTimezone());
        if ($date === false || $date->format('Y-m-d') !== $booking->visitDate) {
            return new StoredBookingValidationResult([
                new StoredBookingIssue('INVALID_VISIT_DATE', StoredBookingIssueCategory::Structural, 'bezoekdatum'),
            ]);
        }

        $issues = [];
        if ($confirmed) {
            if ($date < $today->setTime(0, 0)) {
                $issues[] = new StoredBookingIssue('HISTORICAL_VISIT_DATE', StoredBookingIssueCategory::HistoricalDate, 'bezoekdatum');
            }
            if ($this->disabledDates->isDateDisabled($booking->visitDate)) {
                $issues[] = new StoredBookingIssue('DISABLED_VISIT_DATE', StoredBookingIssueCategory::DisabledDate, 'bezoekdatum');
            }
        }

        return new StoredBookingValidationResult($issues);
    }
}
