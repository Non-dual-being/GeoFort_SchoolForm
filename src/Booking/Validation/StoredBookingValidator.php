<?php
declare(strict_types=1);
namespace GeoFort\Booking\Validation;

use DateTimeImmutable;
use GeoFort\Booking\BookingPolicy;
use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Booking\Stored\StoredBooking;
use GeoFort\Services\Sql\DisabledDatesSqlService;
use GeoFort\Validation\ChoiceModuleSelectionValidator;
use GeoFort\Validation\EducationSelectionValidator;
use GeoFort\Validation\FieldValidationException;
use GeoFort\Validation\ProgramSelectionValidator;

final readonly class StoredBookingValidator
{
    public function __construct(
        private DisabledDatesSqlService $disabledDates,
        private EducationSelectionValidator $educationValidator = new EducationSelectionValidator(),
        private ProgramSelectionValidator $programValidator = new ProgramSelectionValidator(),
        private ChoiceModuleSelectionValidator $moduleValidator = new ChoiceModuleSelectionValidator(),
        private StoredBookingStudentCountValidator $studentValidator = new StoredBookingStudentCountValidator(),
    ) {}

    public function usesConnection(\PDO $pdo): bool
    {
        return $this->disabledDates->usesConnection($pdo);
    }

    public function validateForTargetStatus(StoredBooking $booking, string $targetStatus, DateTimeImmutable $today): StoredBookingValidationResult
    {
        if ($targetStatus !== BookingPolicy::STATUS_CONFIRMED) {
            return new StoredBookingValidationResult([]);
        }

        $issues = [];
        $add = static function (string $code, StoredBookingIssueCategory $category, string $field) use (&$issues): void {
            $issues[] = new StoredBookingIssue($code, $category, $field);
        };
        if (!BookingPolicy::isAllowedStatus($booking->status)) $add('INVALID_STATUS', StoredBookingIssueCategory::Structural, 'status');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $booking->visitDate, $today->getTimezone());
        if ($date === false || $date->format('Y-m-d') !== $booking->visitDate) {
            $add('INVALID_VISIT_DATE', StoredBookingIssueCategory::Structural, 'bezoekdatum');
            return new StoredBookingValidationResult($issues);
        }
        if ($date < $today->setTime(0, 0)) $add('HISTORICAL_VISIT_DATE', StoredBookingIssueCategory::HistoricalDate, 'bezoekdatum');
        if ($this->disabledDates->isDateDisabled($booking->visitDate)) $add('DISABLED_VISIT_DATE', StoredBookingIssueCategory::DisabledDate, 'bezoekdatum');
        foreach (['schoolName' => $booking->schoolName, 'contactFirstName' => $booking->contactFirstName, 'contactLastName' => $booking->contactLastName, 'email' => $booking->email] as $field => $value) {
            if (trim($value) === '') $add('MISSING_CORE_FIELD', StoredBookingIssueCategory::Structural, $field);
        }
        if (!in_array($booking->cjpPassUse, ['ja', 'nee'], true)) $add('INVALID_CJP_SELECTION', StoredBookingIssueCategory::Structural, 'cjpPasGebruik');
        if ($booking->cjpPassUse === 'ja' && (trim($booking->cjpContactName ?? '') === '' || preg_match('/^\d{8,9}$/', $booking->cjpPassNumber ?? '') !== 1)) {
            $add('INCOMPLETE_CJP_DETAILS', $booking->source->isLegacy() ? StoredBookingIssueCategory::HistoricalConfiguration : StoredBookingIssueCategory::Policy, 'cjpPasnummer');
        }
        if ($booking->comments !== null && mb_strlen($booking->comments, 'UTF-8') > 600) {
            $add('COMMENTS_OVER_CURRENT_LIMIT', $booking->source->isLegacy() ? StoredBookingIssueCategory::HistoricalConfiguration : StoredBookingIssueCategory::Policy, 'opmerkingen');
        }
        if (!BookingProgramConfig::isValidSchoolSectorValue($booking->schoolSector)) $add('UNKNOWN_SECTOR', StoredBookingIssueCategory::Structural, 'onderwijsSector');
        if (!$booking->source->hasNormalizedEducationSelection) $add('MISSING_NORMALIZED_EDUCATION_SELECTION', StoredBookingIssueCategory::HistoricalConfiguration, 'educationSelection');
        if (
            $booking->supervisorCount === null
            || $booking->supervisorCount < 0
            || $booking->supervisorCount > BookingPolicy::MAX_SUPERVISORS_PER_BOOKING
        ) {
            $add(
                'CURRENT_CONFIGURATION_MISMATCH',
                $booking->source->isLegacy() ? StoredBookingIssueCategory::HistoricalConfiguration : StoredBookingIssueCategory::Policy,
                'aantalBegeleiders',
            );
        }

        try {
            $education = $this->educationValidator->validate($booking->educationSelection->toJson(), $booking->schoolSector);
            $this->programValidator->validate($booking->program, $booking->schoolSector, $date);
            $this->studentValidator->validate($booking->studentCount, $booking->schoolSector, $booking->program);
            $this->moduleValidator->validate($booking->choiceModuleKey, $booking->schoolSector, $booking->program, $education);
        } catch (FieldValidationException $exception) {
            $category = $booking->source->isLegacy() ? StoredBookingIssueCategory::HistoricalConfiguration : StoredBookingIssueCategory::Policy;
            $add('CURRENT_CONFIGURATION_MISMATCH', $category, $exception->getField());
        } catch (\InvalidArgumentException|\LogicException) {
            $add('INVALID_CONFIGURATION_KEY', StoredBookingIssueCategory::Structural, 'configuratie');
        }
        return new StoredBookingValidationResult($issues);
    }
}
