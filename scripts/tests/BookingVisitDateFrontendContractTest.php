<?php
declare(strict_types=1);
$root=dirname(__DIR__,2).'/';
$panel=file_get_contents($root.'resources/js/admin/components/bookings/BookingVisitDatePanel.vue');
$view=file_get_contents($root.'resources/js/admin/views/DashboardBookingDetailView.vue');
$api=file_get_contents($root.'resources/js/admin/services/dashboardBookingVisitDateApi.ts');
$panelWithoutWhitespace=(string)preg_replace('/\s+/','',$panel);
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
foreach(['Bezoekdatum','AdminDateField','expected:{visitDate:props.visitDate}','proposed:{visitDate:proposed.value}','OVERRIDE_REQUIRED','VISIT_DATE_CONFLICT','BookingRuleOverrideDialog','status','geen e-mail'] as $needle)$assert(str_contains($panel,$needle),"Frontendcontract mist {$needle}.");
$assert(str_contains($panel,'reasons.value={}'),'Een nieuw bewerkmoment wist oude override-redenen niet.');
$assert(str_contains($panelWithoutWhitespace,'if(submitting.value||props.refreshing||!proposed.value)return'),'Dubbele submit of submit tijdens refresh wordt niet geblokkeerd.');
$assert(str_contains($panelWithoutWhitespace,'if(!editing.value)proposed.value=value'),'Conflictrefresh kan de voorgestelde gebruikersdatum wissen.');
$assert(str_contains($panelWithoutWhitespace,'expected:{visitDate:props.visitDate},proposed:{visitDate:proposed.value}'),'Override/conflictretry gebruikt niet één actuele expected/proposed state.');
$assert(str_contains($panelWithoutWhitespace,'result.code==="VISIT_DATE_CONFLICT"')&&str_contains($panelWithoutWhitespace,'true,true'),'Conflict wordt niet als refresh zonder succes afgehandeld.');
$assert(str_contains($view,'BookingVisitDatePanel')&&str_contains($view,'await load(true)'),'Detailrefresh ontbreekt.');
$assert(!str_contains($view,'formatDate(booking.visitDate)'),'Dashboarddetail toont de bezoekdatum buiten het datumcomponent dubbel.');
$assert(str_contains($api,'update-booking-visit-date.php')&&!str_contains($panel,'sendMail'),'Endpoint of mailisolatie klopt niet.');
fwrite(STDOUT,"OK: visit-date frontendcontract geslaagd.\n");
