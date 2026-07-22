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
use GeoFort\Validation\FoodAndDrinkSelectionValidator;
use GeoFort\Validation\ProgramSelectionValidator;
use GeoFort\Validation\StudentCountValidator;
use GeoFort\Validation\SupervisorCountValidator;

final readonly class StoredBookingValidator
{
    public function __construct(
        private DisabledDatesSqlService $disabledDates,
        private EducationSelectionValidator $educationValidator = new EducationSelectionValidator(),
        private ProgramSelectionValidator $programValidator = new ProgramSelectionValidator(),
        private ChoiceModuleSelectionValidator $moduleValidator = new ChoiceModuleSelectionValidator(),
        private StudentCountValidator $studentValidator = new StudentCountValidator(),
        private SupervisorCountValidator $supervisorValidator = new SupervisorCountValidator(),
        private FoodAndDrinkSelectionValidator $foodValidator = new FoodAndDrinkSelectionValidator(),
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

        try {
            $education = $this->educationValidator->validate($booking->educationSelection->toJson(), $booking->schoolSector);
            $this->programValidator->validate($booking->program, $booking->schoolSector, $date);
            $students = $this->studentValidator->validate($booking->studentCount, $booking->schoolSector, $booking->program);
            $this->supervisorValidator->validate($booking->supervisorCount, $students);
            $this->moduleValidator->validate($booking->choiceModuleKey, $booking->schoolSector, $booking->program, $education);
            $food = $booking->foodAndDrinkSelection;
            $this->foodValidator->validate([
                'remiseBreak' => $food->remiseBreak,
                'kazerneBreak' => $food->kazerneBreak,
                'fortgrachtBreak' => $food->fortgrachtBreak,
                'waterijsje' => $food->waterijsje,
                'glasLimonade' => $food->glasLimonade,
                'lunchChoice' => $food->lunchChoice,
                'remiseLunch' => $food->remiseLunch,
            ]);
        } catch (FieldValidationException $exception) {
            $category = $booking->source->isLegacy() ? StoredBookingIssueCategory::HistoricalConfiguration : StoredBookingIssueCategory::Policy;
            $minimumSupervisors = BookingPolicy::getMinimumSupervisorCount(max(1, $booking->studentCount));
            if (
                $exception->getField() === 'aantalBegeleiders'
                && $booking->supervisorCount > 0
                && $booking->supervisorCount < $minimumSupervisors
            ) {
                $issues[] = new StoredBookingIssue(
                    'MINIMUM_SUPERVISORS_NOT_MET',
                    $category,
                    'aantalBegeleiders',
                    metadata: [
                        'studentCount' => $booking->studentCount,
                        'supervisorCount' => $booking->supervisorCount,
                        'minimumSupervisors' => $minimumSupervisors,
                    ],
                );
            } else {
                $add('CURRENT_CONFIGURATION_MISMATCH', $category, $exception->getField());
            }
        } catch (\InvalidArgumentException|\LogicException) {
            $add('INVALID_CONFIGURATION_KEY', StoredBookingIssueCategory::Structural, 'configuratie');
        }
        return new StoredBookingValidationResult($issues);
    }
}
