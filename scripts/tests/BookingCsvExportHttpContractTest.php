<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$csvAction = (string) file_get_contents($root . '/src/Services/Http/Api/Admin/DashboardBookingCsvExportAction.php');
$summaryAction = (string) file_get_contents($root . '/src/Services/Http/Api/Admin/DashboardBookingExportSummaryAction.php');
$metadataAction = (string) file_get_contents($root . '/src/Services/Http/Api/Admin/DashboardBookingExportMetadataAction.php');
$repository = (string) file_get_contents($root . '/src/Services/Sql/BookingExportSqlRepository.php');
$summaryService = (string) file_get_contents($root . '/src/Services/Dashboard/Booking/Export/BookingExportSummaryService.php');
$bootstrap = (string) file_get_contents($root . '/bootstrap.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

foreach ([$csvAction, $summaryAction, $metadataAction] as $action) {
    $assert(str_contains($action, 'PrivatePageBootstrapper'), 'Private adminauth ontbreekt.');
    $assert(str_contains($action, "method !== 'GET'"), 'Read-only GET-contract ontbreekt.');
}
$assert(str_contains($csvAction, 'BookingExportCriteriaFactory'), 'CSV gebruikt gedeelde periodecriteria niet.');
$assert(str_contains($summaryAction, 'BookingExportCriteriaFactory'), 'Summary gebruikt gedeelde periodecriteria niet.');
$assert(str_contains($csvAction, "Content-Type: text/csv; charset=UTF-8"), 'CSV Content-Type ontbreekt.');
$assert(str_contains($csvAction, 'Content-Disposition: attachment'), 'Attachmentheader ontbreekt.');
$assert(str_contains($csvAction, 'Cache-Control: no-store, private'), 'Private no-store ontbreekt.');
$assert(str_contains($csvAction, 'Pragma: no-cache'), 'Pragma no-cache ontbreekt.');
$assert(str_contains($csvAction, 'X-Content-Type-Options: nosniff'), 'nosniff ontbreekt.');
$assert(str_contains($csvAction, "php://output"), 'Server-side streaming ontbreekt.');
$assert(strpos($csvAction, 'prepare($criteria)') < strpos($csvAction, "header('Content-Type"), 'Query start na responseheaders.');
$assert(str_contains($csvAction, 'effectiveStartDate') && str_contains($csvAction, 'effectiveEndDate'), 'Bestandsnaam gebruikt effectieve periode niet.');
$assert(!str_contains($repository, 'SELECT *'), 'Exportrepository gebruikt SELECT *.');
$assert(str_contains($repository, 'WITH selected AS') && str_contains($repository, 'SUM(CASE WHEN'), 'Vaste aggregate-query ontbreekt.');
$assert(str_contains($repository, "bezoekdatum >= '1000-01-01'"), 'Metadata sluit ongeldige nuldatums niet uit.');
$mainSummary = strstr($repository, 'public function summarizeComposition', true);
$assert(is_string($mainSummary) && !str_contains($mainSummary, 'aanvraag_onderwijs_selecties'), 'Hoofdsamenvatting joinet onderwijsselecties.');
$assert(str_contains($repository, 'private const PERIOD_WHERE') && str_contains($repository, 'private const ALIASED_PERIOD_WHERE'), 'Selectiesemantiek is niet centraal consistent.');
$assert(str_contains($repository, 'COUNT(DISTINCT selections.level_key, selections.group_key)'), 'Groepssummary telt geen unieke niveau-groepcombinaties.');
$assert(str_contains($repository, 'a.status IN (%s)') && str_contains($repository, 'vo_composition_bookings'), 'Managementverdeling gebruikt actieve statussen of VO-noemer niet.');
$assert(str_contains($repository, 'summarizeChoiceModules') && str_contains($repository, 'programma = :moduleProgram'), 'Keuzemoduleverdeling heeft geen eigen relevante programmaselectie.');
$assert(str_contains($summaryService, "'requestStats'") && str_contains($summaryService, "'studentStats'")
    && str_contains($summaryService, "'programStats'") && str_contains($summaryService, "'compositionStats'"), 'Summary-DTO heeft geen expliciete managementcategorieën.');
$assert(str_contains($summaryService, '$this->percentage($bookings, $denominator)')
    && str_contains($summaryService, '$denominator > 0'), 'Percentages gebruiken geen expliciete veilige noemer.');
$assert(str_contains($summaryService, 'BookingProgramConfig::MODULE_LABELS')
    && str_contains($summaryService, 'array_slice($rows, 0, 3)'), 'Moduleverdeling gebruikt geen labels of compacte topverdeling.');
$assert(str_contains($repository, 'GROUP BY aanvraag_id, level_key') && str_contains($repository, 'groups_per_level'), 'CSV-selecties worden niet eerst per niveau geaggregeerd.');
$assert(str_contains($repository, "SEPARATOR ', '") && str_contains($repository, "SEPARATOR ' | '"), 'Leesbare selectieseparators ontbreken.');
$assert(str_contains($repository, 'ORDER BY a.bezoekdatum ASC, a.id ASC'), 'Exportvolgorde wijkt af.');
$assert(str_contains($repository, 'MYSQL_ATTR_USE_BUFFERED_QUERY, false'), 'PDO-exportcursor is niet unbuffered.');
foreach ([
    'DashboardBookingCsvExportAction::class =>',
    'DashboardBookingExportMetadataAction::class =>',
    'DashboardBookingExportSummaryAction::class =>',
] as $registration) {
    $assert(str_contains($bootstrap, $registration), "Container mist {$registration}.");
}

echo "Booking export HTTP contract tests passed.\n";
