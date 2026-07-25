<?php
declare(strict_types=1);
$root=dirname(__DIR__,2).'/';$panel=(string)file_get_contents($root.'resources/js/admin/components/bookings/BookingCateringPanel.vue');$view=(string)file_get_contents($root.'resources/js/admin/views/DashboardBookingDetailView.vue');$api=(string)file_get_contents($root.'resources/js/admin/services/dashboardBookingCateringApi.ts');$detailService=(string)file_get_contents($root.'src/Services/Dashboard/Booking/DashboardBookingDetailService.php');$types=(string)file_get_contents($root.'resources/js/admin/types/bookingCatering.ts');$styles=(string)file_get_contents($root.'resources/css/admin/booking-detail.css');
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$assert(str_contains($view,'BookingCateringPanel')&&!str_contains($view,'<dt>Remise break</dt>'),'Detailview gebruikt statische kaart nog.');
$assert(str_contains($api,'/api/admin/requests/update-booking-catering.php'),'Endpoint onjuist.');
foreach(['remiseBreak','kazerneBreak','fortgrachtBreak','waterIce','lemonade','lunchChoice','expected','proposed'] as $needle)$assert(str_contains($panel,$needle),"Frontend mist {$needle}.");
$assert(!str_contains($panel,'mailMode')&&!str_contains($panel,'override')&&!str_contains($panel,'BookingRuleOverrideDialog'),'Frontend bevat mail of overridegedrag.');
$proposed=(string)substr($panel,(int)strpos($panel,'proposed:{'),180);$assert(!str_contains($proposed,'ownPicnic'),'Proposed payload verstuurt ownPicnic onafhankelijk.');
$assert(str_contains($panel,'Geen lunchkeuze vastgelegd')&&str_contains($panel,'!lunchChoice'),'None-keuzecontract ontbreekt.');
$assert(str_contains($types,'| "conflict"')&&str_contains($panel,"lunchChoice==='conflict'")&&!str_contains($detailService,"LUNCH_CONFLICT ? 'none'"),'Conflict-readstate wordt niet ongewijzigd doorgegeven.');
$assert(str_contains($panel,'CATERING_CONFLICT')&&str_contains($panel,'emit("completed"')&&str_contains($view,'await load(true)'),'Conflictrefresh/editcontext ontbreekt.');
$assert(str_contains($panel,'conflictDetected.value=true')&&str_contains($panel,'conflictRefreshReady.value=true')&&str_contains($panel,':disabled="conflictDetected||!lunchChoice"')&&str_contains($panel,':disabled="submitting||!conflictRefreshReady"')&&str_contains($panel,'Actuele serverwaarden:')&&str_contains($panel,'Actuele gegevens overnemen'),'Conflict toont geen actuele serverwaarden of kan vóór refresh/zonder expliciete herbeoordeling opnieuw worden opgeslagen.');
$assert(str_contains($panel,'option.price')&&str_contains($panel,'option.min')&&str_contains($panel,'option.max'),'Centrale prijs/min/max niet gebruikt.');
$assert(!str_contains($styles,'--admin-border-color')&&!str_contains($styles,'--admin-text-muted'),'Cateringstijl gebruikt onbekende CSS-tokens.');
echo "Booking catering frontend contract tests passed; TypeScript wordt door pnpm build gecompileerd.\n";
