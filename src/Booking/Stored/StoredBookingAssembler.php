<?php

declare(strict_types=1);

namespace GeoFort\Booking\Stored;

use GeoFort\Services\Booking\Data\EducationSelectionData;
use GeoFort\Services\Booking\Data\FoodAndDrinkSelectionData;
use RuntimeException;

final class StoredBookingAssembler
{
    /** @param array<string, mixed> $row @param list<array<string, mixed>> $selectionRows */
    public function assemble(array $row, array $selectionRows): StoredBooking
    {
        $id = $this->int($row, 'id');
        if ($id === null || $id <= 0) {
            throw new RuntimeException('Aanvraag bevat een ongeldig id.');
        }

        $groups = [];
        $levels = [];
        $sector = $this->string($row, 'onderwijs_sector');
        foreach ($selectionRows as $selection) {
            $selectionSector = $this->string($selection, 'sector_key');
            $level = $this->string($selection, 'level_key');
            $group = $this->string($selection, 'group_key');
            if ($selectionSector !== $sector) {
                throw new RuntimeException('Onderwijsselectie heeft een afwijkende sector.');
            }
            if (!isset($groups[$level])) {
                $levels[] = $level;
                $groups[$level] = [];
            }
            if (in_array($group, $groups[$level], true)) {
                throw new RuntimeException('Onderwijsselectie bevat een dubbele level/groepcombinatie.');
            }
            $groups[$level][] = $group;
        }

        return new StoredBooking(
            id: $id,
            status: $this->string($row, 'status'),
            visitDate: $this->string($row, 'bezoekdatum'),
            schoolName: $this->string($row, 'schoolnaam'),
            country: $this->string($row, 'land'),
            address: $this->string($row, 'adres'),
            postalCode: $this->string($row, 'postcode'),
            city: $this->string($row, 'plaats'),
            schoolPhone: $this->string($row, 'school_telefoonnummer'),
            contactPhone: $this->string($row, 'contactpersoon_telefoonnummer'),
            contactFirstName: $this->string($row, 'contactpersoon_voornaam'),
            contactLastName: $this->string($row, 'contactpersoon_achternaam'),
            email: $this->string($row, 'email'),
            discoverySource: $this->nullableString($row, 'hoe_kent_u_geofort'),
            cjpPassUse: $this->string($row, 'cjpPasGebruik'),
            cjpContactName: $this->nullableString($row, 'cjpContactpersoonNaam'),
            cjpPassNumber: $this->nullableString($row, 'cjpPasnummer'),
            schoolSector: $sector,
            program: $this->string($row, 'programma'),
            choiceModuleKey: $this->nullableString($row, 'keuzemodule_key'),
            studentCount: $this->int($row, 'aantal_leerlingen'),
            supervisorCount: $this->int($row, 'aantal_begeleiders'),
            educationSelection: new EducationSelectionData($sector, $levels, $groups),
            foodAndDrinkSelection: FoodAndDrinkSelectionData::fromStoredValues(
                $this->int($row, 'remise_break') ?? 0,
                $this->int($row, 'kazerne_break') ?? 0,
                $this->int($row, 'fortgracht_break') ?? 0,
                $this->int($row, 'waterijsje') ?? 0,
                $this->int($row, 'glas_limonade') ?? 0,
                $this->int($row, 'remise_lunch') ?? 0,
                ($this->int($row, 'eigen_picknick') ?? 0) === 1,
            ),
            comments: $this->nullableString($row, 'opmerkingen'),
            termsAccepted: ($this->int($row, 'voorwaarden_akkoord') ?? 0) === 1,
            termsAcceptedAt: $this->nullableString($row, 'voorwaarden_akkoord_op'),
            source: new BookingSourceMetadata(
                $this->nullableString($row, 'source_system'),
                $this->int($row, 'source_record_id'),
                $this->nullableString($row, 'source_record_checksum'),
                $this->int($row, 'source_import_run_id'),
                $selectionRows !== [],
            ),
        );
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) throw new RuntimeException("Aanvraagkolom {$key} is ongeldig.");
        return trim($value);
    }

    /** @param array<string, mixed> $row */
    private function nullableString(array $row, string $key): ?string
    {
        if (!array_key_exists($key, $row) || $row[$key] === null) return null;
        if (!is_string($row[$key])) throw new RuntimeException("Aanvraagkolom {$key} is ongeldig.");
        return trim($row[$key]);
    }

    /** @param array<string, mixed> $row */
    private function int(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;
        if ($value === null || $value === '') return null;
        if (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1)) return (int) $value;
        throw new RuntimeException("Aanvraagkolom {$key} is ongeldig.");
    }
}
