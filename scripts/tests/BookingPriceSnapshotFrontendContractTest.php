<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$panel=file_get_contents($root.'/resources/js/admin/components/bookings/BookingPricePanel.vue');
$view=file_get_contents($root.'/resources/js/admin/views/DashboardBookingDetailView.vue');
$types=file_get_contents($root.'/resources/js/admin/types/bookingDetail.ts');
$api=file_get_contents($root.'/resources/js/admin/services/dashboardLegacyPriceAcceptanceApi.ts');
$action=file_get_contents($root.'/src/Services/Http/Api/Admin/DashboardLegacyBookingPriceAcceptanceAction.php');
foreach(['Financieel vastgelegd','Historische prijs niet beschikbaar','Actuele prijsindicatie – nog niet financieel vastgelegd','Huidige prijs vastleggen','Dit herstelt niet de oorspronkelijke historische prijs','Annuleren','role="alert"'] as $needle)$assert(str_contains($panel,$needle),"Prijsweergave mist {$needle}.");
$assert(str_contains($panel,'Intl.NumberFormat("nl-NL"')&&str_contains($panel,'cents/100'),'Nederlandse centsformattering ontbreekt.');
$assert(str_contains($types,'visitAmountInclVatCents:number')&&str_contains($types,'historical_price_unavailable'),'Typed cents/statecontract ontbreekt.');
$assert(str_contains($view,'BookingPricePanel')&&!str_contains($view,'Prijsindicatie</h2>'),'Oude actuele prijsweergave is nog actief.');
$assert(str_contains($api,'explicitlyAccepted:true')&&str_contains($api,'X-CSRF-Token'),'Frontendacceptatie is niet expliciet/CSRF-beveiligd.');
$assert(str_contains($action,"CSRF_SCOPE='accept-legacy-booking-price'")&&str_contains($action,"!==true"),'Backend vereist geen expliciete acceptatie.');
echo "Booking price snapshot frontend contract tests passed.\n";
