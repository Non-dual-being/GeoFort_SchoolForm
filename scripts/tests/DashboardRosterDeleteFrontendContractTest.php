<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$view = (string) file_get_contents(
    $root . '/resources/js/admin/views/DashboardRostersView.vue',
);
$api = (string) file_get_contents(
    $root . '/resources/js/admin/services/dashboardRosterApi.ts',
);
$types = (string) file_get_contents(
    $root . '/resources/js/admin/types/roster.ts',
);
$css = (string) file_get_contents(
    $root . '/resources/css/admin/rosters.css',
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

$assertContains(
    $view,
    'deleteDashboardRoster',
    'Het roosteroverzicht moet de delete-API gebruiken.',
);
$assertContains(
    $view,
    'v-if="canDelete(item)"',
    'Verwijderbare roosters moeten een verwijderactie tonen.',
);
$assertContains(
    $view,
    '@click.stop="requestDelete(item)"',
    'De verwijderactie mag de klikbare roosterregel niet openen.',
);
$assertContains(
    $view,
    'AdminConfirmationControl',
    'Rooster verwijderen vereist een expliciete bevestiging.',
);
$assertContains(
    $view,
    'De onderwijsaanvraag blijft bestaan. Alleen het rooster wordt verwijderd.',
    'Bij gekoppelde roosters moet duidelijk zijn dat de aanvraag blijft bestaan.',
);
$assertContains(
    $api,
    '"/api/admin/rosters/delete.php"',
    'Frontend moet het aparte rooster-delete-endpoint gebruiken.',
);
$assertContains(
    $types,
    'export interface DashboardRosterDeleteResponse',
    'Typecontract voor rooster verwijderen ontbreekt.',
);
$assertContains(
    $css,
    'GeoFort V8B2A2b: rooster verwijderen vanuit overzicht',
    'Rooster-delete styling ontbreekt.',
);

fwrite(STDOUT, "OK: roster V8B2A2b delete frontend contract passed.\n");
