<?php

declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Roster;

use GeoFort\Booking\Stored\StoredBooking;
use GeoFort\Services\Booking\Data\EducationSelectionSummary;
use GeoFort\Services\Booking\Presentation\EducationSelectionSummaryFactory;

/** Additional display fields only; never consults live sources for a final snapshot. */
final class RosterPresentationInformation
{
    /** @return array{school:array<string,string>,education:array{selectionLabels:list<string>}} */
    public static function fromBooking(?StoredBooking $booking): array
    {
        return [
            'school' => [
                'address' => $booking?->address ?? '',
                'postalCode' => $booking?->postalCode ?? '',
                'country' => $booking?->country ?? '',
                'schoolPhone' => $booking?->schoolPhone ?? '',
                'contactEmail' => $booking?->email ?? '',
            ],
            'education' => ['selectionLabels' => $booking === null ? [] : self::selectionLabels(
                (new EducationSelectionSummaryFactory())->fromData($booking->educationSelection),
            )],
        ];
    }

    /** @param array<string,mixed> $content @return array<string,mixed> */
    public static function fromSnapshot(array $content): array
    {
        $presentation = $content['presentation'];
        $booking = $content['stored']['booking'] ?? [];
        $selections = $content['stored']['educationSelections'] ?? [];
        $selections = array_map(static fn (array $row): array => $row + ['level_position' => 0, 'group_position' => 0],
            array_values(array_filter($selections, static fn (array $row): bool =>
                is_string($row['level_label'] ?? null) && is_string($row['group_label'] ?? null))));
        // Array union preserves even deliberately empty/null frozen presentation values.
        $presentation['school'] = ($presentation['school'] ?? []) + [
            'address' => (string) ($booking['adres'] ?? ''),
            'postalCode' => (string) ($booking['postcode'] ?? ''),
            'country' => (string) ($booking['land'] ?? ''),
            'schoolPhone' => (string) ($booking['school_telefoonnummer'] ?? ''),
            'contactEmail' => (string) ($booking['email'] ?? ''),
        ];
        $presentation['education'] = ($presentation['education'] ?? []) + [
            'selectionLabels' => self::selectionLabels(
                (new EducationSelectionSummaryFactory())->fromDatabaseRows($selections),
            ),
        ];
        return $presentation;
    }

    /** @return list<string> */
    private static function selectionLabels(EducationSelectionSummary $summary): array
    {
        $labels = [];
        foreach ($summary->groupsByLevelLabel as $groups) {
            foreach ($groups as $label) {
                if (trim($label) !== '' && !in_array($label, $labels, true)) $labels[] = $label;
            }
        }
        return $labels;
    }
}
