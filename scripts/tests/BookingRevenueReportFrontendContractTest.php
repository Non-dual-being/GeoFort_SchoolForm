<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);$view=(string)file_get_contents($root.'/resources/js/admin/views/DashboardBookingRevenueView.vue');$table=(string)file_get_contents($root.'/resources/js/admin/components/revenue/RevenueBookingsTable.vue');$service=(string)file_get_contents($root.'/resources/js/admin/services/dashboardBookingRevenueApi.ts');$types=(string)file_get_contents($root.'/resources/js/admin/types/bookingRevenue.ts');$money=(string)file_get_contents($root.'/resources/js/admin/utils/money.ts');$router=(string)file_get_contents($root.'/resources/js/admin/router/index.ts');$navigation=(string)file_get_contents($root.'/resources/js/admin/components/layout/DashboardNavigation.vue');
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$assert(str_contains($router,'path: "/omzet"')&&str_contains($navigation,'to="/omzet"'),'Omzetroute of navigatie ontbreekt.');
$assert(strpos($navigation,'Agenda')<strpos($navigation,'Export')&&strpos($navigation,'Export')<strpos($navigation,'Omzet'),'Navigatievolgorde onjuist.');
$assert(str_contains($service,'revenue.php')&&str_contains($service,'URLSearchParams({ startDate, endDate })'),'Getypepte periode-API ontbreekt.');
foreach(['definitiveRevenue','potentialRevenue','bookings: RevenueBooking[]','amounts: RevenueAmounts | null'] as $contract)$assert(str_contains($types,$contract),"Responsecontract mist {$contract}.");
$assert(str_contains($view,'Omzetrapportage laden')&&str_contains($view,'role="alert"')&&str_contains($view,'Geen boekingen binnen deze bezoekperiode'),'Loading-, fout- of lege staat ontbreekt.');
$assert(str_contains($view,'report.value.definitiveRevenue')&&!str_contains($view,'reduce('),'Frontend herberekent omzet of gebruikt backendtotalen niet.');
$assert(str_contains($money,'cents / 100')&&str_contains($money,'currency: "EUR"'),'Centrale centenformatter ontbreekt.');
$assert(str_contains($table,'v-if="booking.amounts"')&&str_contains($table,'Prijs ontbreekt')&&str_contains($table,'colspan="3"'),'Ontbrekende prijs wordt misleidend getoond.');
echo "Booking revenue frontend contract tests passed.\n";
