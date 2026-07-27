<?php
declare(strict_types=1);
$root=dirname(__DIR__,2).'/';$panel=file_get_contents($root.'resources/js/admin/components/bookings/BookingProgramPanel.vue');$view=file_get_contents($root.'resources/js/admin/views/DashboardBookingDetailView.vue');$detail=file_get_contents($root.'src/Services/Dashboard/Booking/DashboardBookingDetailService.php');$service=file_get_contents($root.'src/Services/Booking/Program/BookingProgramChangeService.php');
$flat=(string)preg_replace('/\s+/','',$panel);$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
foreach(['Huidig programma','Schoolsector','Bezoekdatum','expected:{program:expected.value}','proposed:{program:proposed.value}','OVERRIDE_REQUIRED','PROGRAM_CONFLICT','BookingRuleOverrideDialog','conflicted','reconsider'] as $needle)$assert(str_contains($panel,$needle),"Frontendcontract mist {$needle}.");
$assert(str_contains($flat,'if(submitting.value||props.refreshing||conflicted.value)return'),'Dubbele submit/conflictretry wordt niet geblokkeerd.');
$assert(str_contains($view,'await load(true)')&&str_contains($view,':key="`${booking.id}-${booking.education.program}`"'),'Detailrefresh of kalendercache-invalidatie ontbreekt.');
$assert(substr_count($view,'booking.education.programLabel')===2,'Onverwachte dubbele statische programmaweergave.');
$assert(str_contains($detail,'BookingProgramConfig::PROGRAMS')&&!str_contains($panel,'Ochtendprogramma')&&!str_contains($panel,'Dagprogramma'),'Frontend gebruikt een hardcoded programmalijst.');
$assert(str_contains($service,'withProgram')&&!str_contains($service,'price')&&!str_contains($service,'Mail'),'Programmaflow reset velden, schrijft prijs of verstuurt mail.');
fwrite(STDOUT,"OK: programma frontendcontract geslaagd.\n");
