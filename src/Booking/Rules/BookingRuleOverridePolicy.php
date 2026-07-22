<?php
declare(strict_types=1);
namespace GeoFort\Booking\Rules;

final class BookingRuleOverridePolicy
{
    public const PERMISSION_OVERRIDE = 'booking.rules.override';

    public function definition(string $code): BookingRuleDefinition
    {
        return match ($code) {
            'INCOMPLETE_CJP_DETAILS' => $this->warning($code, 'CJP-gegevens zijn onvolledig', 'Bij deze aanvraag is aangegeven dat CJP wordt gebruikt, maar het CJP-pasnummer ontbreekt.'),
            'MINIMUM_SUPERVISORS_NOT_MET' => $this->warning($code, 'Er zijn minder begeleiders opgegeven dan volgens de huidige regels vereist is', 'Het opgegeven aantal begeleiders is lager dan het actuele minimum.'),
            'DISABLED_VISIT_DATE' => $this->warning($code, 'Deze bezoekdatum is geblokkeerd', 'De bezoekdatum staat in de actuele lijst met geblokkeerde datums.'),
            'SCHOOL_LIMIT_EXCEEDED' => $this->warning($code, 'Maximum aantal scholen op deze datum wordt overschreden', 'Met deze aanvraag wordt het actuele maximum aantal scholen overschreden.'),
            'STUDENT_LIMIT_EXCEEDED' => $this->warning($code, 'Maximum aantal leerlingen op deze datum wordt overschreden', 'Met deze aanvraag wordt het actuele maximum aantal leerlingen overschreden.'),
            default => new BookingRuleDefinition($code, BookingRuleSeverity::Error, false, null, 'Boekingsregel blokkeert deze wijziging', 'Deze regel kan niet worden overschreven.'),
        };
    }

    private function warning(string $code, string $title, string $description): BookingRuleDefinition
    {
        return new BookingRuleDefinition($code, BookingRuleSeverity::Warning, true, self::PERMISSION_OVERRIDE, $title, $description);
    }
}
