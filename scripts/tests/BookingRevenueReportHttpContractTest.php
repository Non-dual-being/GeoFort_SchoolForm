<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$action=(string)file_get_contents($root.'/src/Services/Http/Api/Admin/DashboardBookingRevenueReportAction.php');$repository=(string)file_get_contents($root.'/src/Services/Sql/BookingRevenueReportSqlRepository.php');$bootstrap=(string)file_get_contents($root.'/bootstrap.php');$endpoint=(string)file_get_contents($root.'/public/api/admin/requests/revenue.php');
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$assert(str_contains($action,'PrivatePageBootstrapper')&&str_contains($action,"method !== 'GET'"),'Adminauth of GET-only ontbreekt.');
$assert(str_contains($action,'FieldValidationException')&&str_contains($action,'serverError'),'Validatie of afgeschermde serverfout ontbreekt.');
$assert(str_contains($repository,'prepare($sql)')&&str_contains($repository,':startDate')&&str_contains($repository,':endDate'),'Prepared periodequery ontbreekt.');
$assert(str_contains($repository,'MAX(sequence_number)')&&str_contains($repository,'GROUP BY booking_id'),'Nieuwste-snapshotselectie ontbreekt.');
$assert(str_contains($repository,'findAvailableVisitDateRange')&&str_contains($repository,"status IN (:confirmed, :option)")&&str_contains($repository,"bezoekdatum >= '1000-01-01'"),'Globale actieve ISO-datumgrenzen ontbreken.');
$assert(str_contains($repository,'LEFT JOIN')&&str_contains($repository,'ORDER BY a.bezoekdatum ASC, a.id ASC'),'Legacyprijzen of stabiele sortering ontbreken.');
$assert(str_contains($action,'availableVisitDateRange->isEmpty()')&&str_contains($action,'emptyReport'),'Lege planningspopulatie slaat de rapportquery niet aantoonbaar over.');
$assert(!str_contains($action,'SELECT ')&&!str_contains($endpoint,'SELECT '),'SQL staat in endpoint/action.');
$assert(str_contains($bootstrap,'DashboardBookingRevenueReportAction::class =>'),'Action niet geregistreerd.');
$list=(string)file_get_contents($root.'/src/Services/Http/Api/Admin/DashboardBookingRevenueListAction.php');$csv=(string)file_get_contents($root.'/src/Services/Http/Api/Admin/DashboardBookingRevenueCsvExportAction.php');
$assert(str_contains($list,'PrivatePageBootstrapper')&&str_contains($csv,'PrivatePageBootstrapper'),'Lijst of export mist dashboardauthenticatie.');
$assert(str_contains($repository,'LIMIT :limit OFFSET :offset')&&str_contains($repository,'countRows'),'Server-side paginering ontbreekt.');
$assert(str_contains($csv,"Content-Type: text/csv; charset=UTF-8")&&str_contains($csv,'exportRows($criteria)'),'CSV-headers of gedeelde selectie ontbreekt.');
echo "Booking revenue HTTP contract tests passed.\n";
