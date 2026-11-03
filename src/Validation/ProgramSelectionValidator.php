<?php
declare(strict_types=1);
namespace GeoFort\Validation;

use DateTimeInterface;
use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Services\Sql\BookingProgramAccessSqlRepository;

final class ProgramSelectionValidator
{
    public function __construct(private readonly ?BookingProgramAccessSqlRepository $access = null) {}

    public function validate(string $program, string $schoolSector, DateTimeInterface $visitDate): string
    {
        $this->validateExisting($program, $schoolSector);
        $allowed = $program === 'ochtend' && $this->access !== null
            ? $this->access->policy()->allows($program, null, $visitDate->format('Y-m-d'))
            : BookingProgramConfig::isProgramAllowedForWeekday($program, (int) $visitDate->format('N'));
        if (!$allowed) throw new FieldValidationException('programma', 'Het gekozen programma is niet opengesteld voor deze datum.');
        return $program;
    }

    public function validateOffer(string $program, string $sector, DateTimeInterface $date, ?string $version): string
    {
        $this->validate($program, $sector, $date);
        if ($this->access !== null && !$this->access->policy()->allows($program, $version, $date->format('Y-m-d'))) {
            throw new FieldValidationException('programma', 'Dit programma is niet opengesteld voor de gekozen bezoekdatum. Kies een ander programma of een andere datum.');
        }
        return $program;
    }

    public function validateExisting(string $program, string $schoolSector): string
    {
        if (!BookingProgramConfig::programExists($program)) throw new FieldValidationException('programma', 'Kies een geldig programma.');
        if (!BookingProgramConfig::isProgramAllowedForSchoolSector($program, $schoolSector)) {
            throw new FieldValidationException('programma', 'Het gekozen programma past niet bij de onderwijssector.');
        }
        return $program;
    }
}
