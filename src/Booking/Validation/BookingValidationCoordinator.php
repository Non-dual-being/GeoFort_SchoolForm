<?php

declare(strict_types=1);

namespace GeoFort\Booking\Validation;

use GeoFort\Booking\Rules\BookingRuleOverridePolicy;
use GeoFort\Booking\Rules\BookingRuleSeverity;
use GeoFort\Booking\Stored\StoredBooking;

final readonly class BookingValidationCoordinator
{
    public function __construct(
        private ProgramStudentLimitValidator $programStudentLimitValidator = new ProgramStudentLimitValidator(),
        private MinimumSupervisorValidator $minimumSupervisorValidator = new MinimumSupervisorValidator(),
        private BookingRuleOverridePolicy $overridePolicy = new BookingRuleOverridePolicy(),
        private StoredBookingCateringValidator $cateringValidator = new StoredBookingCateringValidator(),
    ) {}

    public function validate(
        BookingValidationProfile $profile,
        StoredBooking $booking,
        BookingValidationContext $context = new BookingValidationContext(),
    ): StoredBookingValidationResult {
        $issues = $profile === BookingValidationProfile::ConfirmBooking
            ? $context->baseIssues
            : [];

        if ($profile === BookingValidationProfile::ChangeCatering) {
            return new StoredBookingValidationResult(
                $this->classifyAndDeduplicate($this->cateringValidator->validate($booking)),
            );
        }

        if ($profile === BookingValidationProfile::ConfirmBooking) {
            array_push($issues, ...$this->cateringValidator->validate($booking));
        }

        $programIssue = $this->programStudentLimitValidator->validate($booking);
        if ($programIssue !== null) $issues[] = $programIssue;

        $supervisorIssue = $this->minimumSupervisorValidator->validate($booking);
        if ($supervisorIssue !== null) $issues[] = $supervisorIssue;

        if (
            $profile !== BookingValidationProfile::ChangeAttendanceDraft
            && $context->capacityIssue !== null
        ) {
            $issues[] = $context->capacityIssue;
        }

        return new StoredBookingValidationResult($this->classifyAndDeduplicate($issues));
    }

    /**
     * @param list<StoredBookingIssue> $issues
     * @return list<StoredBookingIssue>
     */
    private function classifyAndDeduplicate(array $issues): array
    {
        $result = [];
        $positions = [];

        foreach ($issues as $issue) {
            $classified = $this->classify($issue);
            $key = $classified->code . "\0" . $classified->field;
            if (isset($positions[$key])) {
                $position = $positions[$key];
                if ($result[$position]->overridable && !$classified->overridable) {
                    $result[$position] = $classified;
                }
                continue;
            }

            $positions[$key] = count($result);
            $result[] = $classified;
        }

        return $result;
    }

    private function classify(StoredBookingIssue $issue): StoredBookingIssue
    {
        $definition = $this->overridePolicy->definition($issue->code);
        $metadata = $issue->code === 'INCOMPLETE_CJP_DETAILS'
            ? ['field' => 'cjpPasnummer', 'cjpSelected' => true]
            : $issue->metadata;

        return new StoredBookingIssue(
            $issue->code,
            $issue->category,
            $issue->field,
            $definition->overridable ? $definition->severity : BookingRuleSeverity::Error,
            $definition->overridable,
            $definition->title,
            $definition->description,
            $metadata,
        );
    }
}
