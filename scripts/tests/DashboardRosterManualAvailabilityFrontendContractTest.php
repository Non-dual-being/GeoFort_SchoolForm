<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$view = (string) file_get_contents(
    $root . '/resources/js/admin/views/DashboardRosterCreateView.vue',
);
$dateField = (string) file_get_contents(
    $root . '/resources/js/admin/components/form/AdminDateField.vue',
);
$disabledDatesApi = (string) file_get_contents(
    $root . '/resources/js/services/api/bookingDisabledDatesApi.ts',
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
        throw new RuntimeException($message . " Ongewenst: {$needle}");
    }
};

$assertContains(
    $view,
    'import AdminDateField from "../components/form/AdminDateField.vue";',
    'Handmatige roosterwizard moet dezelfde datumcomponent als het dashboard gebruiken.',
);
$assertContains(
    $view,
    'fetchDisabledDates()',
    'Handmatige roosterwizard moet de bestaande datum-beschikbaarheidsbron gebruiken.',
);
$assertContains(
    $view,
    'program.allowedSchoolTypes.includes(manualForm.sector)',
    'Programma-opties moeten via de bestaande BookingProgramConfig op sector filteren.',
);
$assertContains(
    $view,
    ':disabled-dates="manualDisabledDates"',
    'Niet-boekbare bezoekdatums moeten in de kalender uitgeschakeld worden.',
);
$assertContains(
    $view,
    'Rooster vanuit aanvraag',
    'Bronwissel vanuit handmatig rooster moet plannerstaal gebruiken.',
);
$assertContains(
    $view,
    'Zelfstandig rooster',
    'Bronwissel vanuit aanvraag moet plannerstaal gebruiken.',
);
$assertNotContains(
    $view,
    'booking_id = NULL',
    'Technische database-uitleg hoort niet in de plannerinterface.',
);
$assertNotContains(
    $view,
    'nep-aanvraag',
    'Technische implementatie-uitleg hoort niet in de plannerinterface.',
);
$assertContains(
    $dateField,
    'disabledDates?: string[];',
    'AdminDateField moet autoritatief uitgeschakelde datums kunnen ontvangen.',
);
$assertContains(
    $dateField,
    'disable: [...props.disabledDates]',
    'Flatpickr moet de uitgeschakelde datums toepassen.',
);
$assertContains(
    $disabledDatesApi,
    '"/api/getDisabledDates.php"',
    'Disabled-dates endpoint moet vanaf dashboard absoluut vanaf de webroot worden aangeroepen.',
);

fwrite(STDOUT, "OK: manual roster V8B2A1 UX + availability frontend contract passed.\n");
