<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$dateField = (string) file_get_contents(
    $root . '/resources/js/admin/components/form/AdminDateField.vue',
);
$flatpickrCss = (string) file_get_contents(
    $root . '/resources/css/admin/flatpickr.css',
);

$assertContains = static function (
    string $haystack,
    string $needle,
    string $message,
): void {
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message . " Ontbreekt: {$needle}");
    }
};

$assertNotContains = static function (
    string $haystack,
    string $needle,
    string $message,
): void {
    if (str_contains($haystack, $needle)) {
        throw new RuntimeException($message . " Onverwacht aanwezig: {$needle}");
    }
};

/*
 * Test alleen het gewenste contract, niet de interne functienaam.
 * De eerdere test was te specifiek en controleerde implementatiedetails.
 */
$assertContains(
    $dateField,
    'dayElement.classList.add("admin-flatpickr-day-has-help")',
    'Niet-beschikbare kalenderdagen moeten de help-state behouden.',
);
$assertContains(
    $dateField,
    'dayElement.title = `${reason.title} - ${reason.description}`',
    'Niet-beschikbare kalenderdagen moeten hun reden als cursor-uitleg behouden.',
);
$assertContains(
    $dateField,
    'admin-flatpickr-help',
    'De bestaande uitlegbalk onder de kalender moet behouden blijven.',
);
$assertNotContains(
    $dateField,
    'marker.className = "admin-flatpickr-day-help"',
    'Er mag geen zichtbaar vraagteken op de kalenderdag zelf staan.',
);

$assertContains(
    $flatpickrCss,
    'GeoFort V8B2A2a1: rustige disabled-datums in roosteragenda',
    'De rustige dashboardstijl voor disabled datums ontbreekt.',
);
$assertContains(
    $flatpickrCss,
    'text-decoration: line-through;',
    'Disabled datums moeten subtiel doorgestreept blijven.',
);
$assertContains(
    $flatpickrCss,
    'cursor: help !important;',
    'De help-cursor moet behouden blijven.',
);
$assertContains(
    $flatpickrCss,
    '.admin-flatpickr-help',
    'De styling van de bestaande reden-uitlegbalk moet behouden blijven.',
);

fwrite(
    STDOUT,
    "OK: manual roster V8B2A2a1 calm disabled-date UX contract passed.\n",
);
