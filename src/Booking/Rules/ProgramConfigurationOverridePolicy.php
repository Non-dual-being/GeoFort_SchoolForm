<?php
declare(strict_types=1);
namespace GeoFort\Booking\Rules;

final readonly class ProgramConfigurationOverridePolicy
{
    public const PROGRAM_WEEKDAY_MISMATCH = 'PROGRAM_WEEKDAY_MISMATCH';

    public function __construct(private BookingRuleOverridePolicy $general = new BookingRuleOverridePolicy()) {}

    public function definition(string $code): BookingRuleDefinition
    {
        if ($code === self::PROGRAM_WEEKDAY_MISMATCH) {
            return new BookingRuleDefinition(
                $code,
                BookingRuleSeverity::Warning,
                true,
                BookingRuleOverridePolicy::PERMISSION_OVERRIDE,
                'Ochtendprogramma buiten woensdag',
                'Het Ochtendprogramma is normaal alleen op woensdag beschikbaar.',
            );
        }
        return $this->general->definition($code);
    }
}
