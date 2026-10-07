<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$view = (string) file_get_contents(
    $root . '/resources/js/admin/views/DashboardRosterCreateView.vue',
);

foreach ([
    'type BookingPickerPurpose = "booking_locked" | "custom";',
    'manualLinkedBooking',
    'openManualBookingPicker',
    'useBookingForManual',
    'clearManualBookingLink',
    'bookingId: manualLinkedBooking.value?.id ?? null',
    'Aanvraag koppelen (optioneel)',
    'Rooster op maat',
    'Koppelen <span aria-hidden="true">&rarr;</span>',
    'const isLinkedBookingDate =',
] as $needle) {
    if (!str_contains($view, $needle)) {
        throw new RuntimeException("D3B missing: {$needle}");
    }
}

fwrite(STDOUT, "OK: D3B frontend contract passed.\n");