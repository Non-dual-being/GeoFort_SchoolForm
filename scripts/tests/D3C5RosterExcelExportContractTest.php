<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$files = [
    '/src/Services/Dashboard/Roster/RosterXlsxWriter.php',
    '/src/Services/Http/Api/Admin/DashboardRosterExcelExportAction.php',
    '/public/api/admin/rosters/export-xlsx.php',
    '/resources/js/admin/services/dashboardRosterExportApi.ts',
    '/resources/js/admin/views/DashboardRosterDetailView.vue',
];

$content = '';
foreach ($files as $file) {
    $path = $root . $file;
    if (!is_file($path)) {
        throw new RuntimeException('Excel-exportbestand ontbreekt: ' . $file);
    }
    $content .= (string) file_get_contents($path);
}

foreach ([
    'RosterXlsxWriter',
    'DashboardRosterExcelExportAction',
    '/api/admin/rosters/export-xlsx.php',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'Printrooster',
    'Dagrooster',
    'Docentrooster',
    'Excel exporteren',
    'downloadDashboardRosterXlsx',
    'privatePageBootstrapper->init()',
    'methodNotAllowed()',
    'FILTER_VALIDATE_INT',
    'getPlan((int) $id)',
    'Cache-Control: no-store, private',
    'readfile($temporaryPath)',
] as $needle) {
    if (!str_contains($content, $needle)) {
        throw new RuntimeException('Excel-exportcontract mist: ' . $needle);
    }
}

fwrite(STDOUT, "OK: styled roster Excel export contract passed.\n");
