<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$view = (string) file_get_contents(
    $root . '/resources/js/admin/views/DashboardRostersView.vue',
);
$css = (string) file_get_contents(
    $root . '/resources/css/admin/rosters.css',
);

$assertContains = static function (string $haystack, string $needle, string $message): void {
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message . " Ontbreekt: {$needle}");
    }
};

$assertNotContains = static function (string $haystack, string $needle, string $message): void {
    if (str_contains($haystack, $needle)) {
        throw new RuntimeException($message . " Onverwacht aanwezig: {$needle}");
    }
};

$start = strpos($css, '.admin-roster-delete-trigger {');
$end = $start === false ? false : strpos($css, '.admin-roster-delete-dialog', $start);

if ($start === false || $end === false || $end <= $start) {
    throw new RuntimeException('Kon het CSS-blok van de rooster-verwijderknop niet afbakenen.');
}

$deleteCss = substr($css, $start, $end - $start);

$assertContains(
    $deleteCss,
    'background: #0f2f66;',
    'Normale verwijderknop moet GeoFort-blauw blijven.',
);
$assertContains(
    $deleteCss,
    'background: #173f80;',
    'Hover moet alleen naar een iets lichter blauw gaan.',
);
$assertContains(
    $deleteCss,
    'border-color: #315b9c;',
    'Hover moet een rustige blauwe rand krijgen.',
);
$assertContains(
    $deleteCss,
    'filter: none;',
    'Het prullenbakicoon mag geen rode hoverfilter hebben.',
);
$assertContains(
    $deleteCss,
    'transform: none;',
    'De delete-knop moet expliciet zonder lift blijven.',
);
$assertNotContains(
    $deleteCss,
    'translateY(-1px)',
    'Alleen de delete-knop mag niet liften; andere roostercomponenten mogen hun eigen hover houden.',
);
$assertNotContains(
    $deleteCss,
    '#dc2626',
    'De delete-hover mag geen rood meer bevatten.',
);
$assertNotContains(
    $deleteCss,
    'drop-shadow',
    'De rode icooncontour mag niet meer aanwezig zijn.',
);
$assertContains(
    $view,
    'class="admin-roster-delete-card-trigger"',
    'Kaartweergave moet dezelfde rustige delete-stijl gebruiken.',
);
$assertContains(
    $view,
    'title="Rooster verwijderen"',
    'Desktopknop moet een duidelijke verwijdertooltip behouden.',
);

fwrite(STDOUT, "OK: roster V8B2A2d scoped calm blue delete hover contract passed.\n");
