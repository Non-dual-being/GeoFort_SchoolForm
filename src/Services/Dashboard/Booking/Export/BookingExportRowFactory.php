<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking\Export;

use GeoFort\Booking\BookingPolicy;
use GeoFort\Booking\BookingProgramConfig;

final readonly class BookingExportRowFactory
{
    public const HEADERS = [
        'Boeking-ID', 'Status', 'Bezoekdatum', 'Programma', 'Sector',
        'Aantal leerlingen', 'Aantal begeleiders', 'Geselecteerde niveaus',
        'Geselecteerde groepen', 'Groepen per niveau', 'Keuzemodule', 'Schoolnaam',
        'Straat en huisnummer', 'Postcode', 'Plaats', 'Land',
        'Schooltelefoonnummer', 'Naam contactpersoon',
        'E-mailadres contactpersoon', 'Telefoonnummer contactpersoon',
        'Pauze Remise', 'Pauze Kazerne', 'Pauze Fortgracht',
        'Waterijsjes', 'Glazen limonade', 'Remiselunches', 'Eigen picknick',
        'CJP-pas gebruikt', 'CJP-contactpersoon', 'CJP-pasnummer',
        'Hoe kent u GeoFort', 'Opmerkingen',
    ];

    public function __construct(private SpreadsheetFormulaEscaper $escaper) {}

    /** @param array<string, mixed> $row */
    public function create(array $row): BookingExportRow
    {
        $program = $this->string($row, 'programma');
        $sector = $this->string($row, 'onderwijs_sector');
        $module = $this->nullableString($row, 'keuzemodule_key');
        $contactName = trim(
            $this->string($row, 'contactpersoon_voornaam') . ' '
            . $this->string($row, 'contactpersoon_achternaam')
        );

        return new BookingExportRow([
            (string) $this->integer($row, 'id'),
            $this->text($this->string($row, 'status')),
            $this->string($row, 'bezoekdatum'),
            $this->text(BookingPolicy::PROGRAM_LABELS[$program] ?? $this->unknownLabel($program)),
            $this->text(isset(BookingProgramConfig::SCHOOL_TYPES_BY_KEY[$sector]['label'])
                ? (string) BookingProgramConfig::SCHOOL_TYPES_BY_KEY[$sector]['label']
                : $this->unknownLabel($sector)),
            $this->nullableIntegerText($row, 'aantal_leerlingen'),
            $this->nullableIntegerText($row, 'aantal_begeleiders'),
            $this->text($this->nullableString($row, 'level_labels') ?? ''),
            $this->text($this->nullableString($row, 'group_labels') ?? ''),
            $this->text($this->nullableString($row, 'groups_per_level') ?? ''),
            $this->text($module === null
                ? 'Geen keuzemodule'
                : (BookingProgramConfig::MODULE_LABELS[$module] ?? $this->unknownLabel($module))),
            $this->text($this->string($row, 'schoolnaam')),
            $this->text($this->string($row, 'adres')),
            $this->text($this->string($row, 'postcode')),
            $this->text($this->string($row, 'plaats')),
            $this->text($this->string($row, 'land')),
            $this->text($this->string($row, 'school_telefoonnummer')),
            $this->text($contactName),
            $this->text($this->string($row, 'email')),
            $this->text($this->string($row, 'contactpersoon_telefoonnummer')),
            $this->integerText($row, 'remise_break'),
            $this->integerText($row, 'kazerne_break'),
            $this->integerText($row, 'fortgracht_break'),
            $this->integerText($row, 'waterijsje'),
            $this->integerText($row, 'glas_limonade'),
            $this->integerText($row, 'remise_lunch'),
            $this->booleanLabel($row, 'eigen_picknick'),
            $this->text($this->string($row, 'cjpPasGebruik')),
            $this->text($this->nullableString($row, 'cjpContactpersoonNaam') ?? ''),
            $this->text($this->nullableString($row, 'cjpPasnummer') ?? ''),
            $this->text($this->nullableString($row, 'hoe_kent_u_geofort') ?? ''),
            $this->text($this->nullableString($row, 'opmerkingen') ?? ''),
        ]);
    }

    private function text(string $value): string
    {
        return $this->escaper->escape($value);
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $key): string
    {
        return isset($row[$key]) && is_string($row[$key]) ? $row[$key] : '';
    }

    /** @param array<string, mixed> $row */
    private function nullableString(array $row, string $key): ?string
    {
        $value = $this->string($row, $key);
        return trim($value) === '' ? null : $value;
    }

    /** @param array<string, mixed> $row */
    private function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? 0;
        return is_int($value) || (is_string($value) && preg_match('/^\d+$/', $value) === 1)
            ? (int) $value
            : 0;
    }

    /** @param array<string, mixed> $row */
    private function integerText(array $row, string $key): string
    {
        return (string) $this->integer($row, $key);
    }

    /** @param array<string, mixed> $row */
    private function nullableIntegerText(array $row, string $key): string
    {
        return ($row[$key] ?? null) === null ? '' : $this->integerText($row, $key);
    }

    /** @param array<string, mixed> $row */
    private function booleanLabel(array $row, string $key): string
    {
        return $this->integer($row, $key) === 1 ? 'Ja' : 'Nee';
    }

    private function unknownLabel(string $value): string
    {
        $label = trim(str_replace(['-', '_'], ' ', $value));
        return ($label !== '' ? $label : 'Onbekend') . ' (onbekend)';
    }
}
