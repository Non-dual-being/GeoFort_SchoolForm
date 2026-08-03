<?php
declare(strict_types=1);
$root=dirname(__DIR__,2).'/';$panel=file_get_contents($root.'resources/js/admin/components/bookings/BookingProgramPanel.vue');$choice=file_get_contents($root.'resources/js/admin/components/bookings/program/BookingProgramChoiceStep.vue');$view=file_get_contents($root.'resources/js/admin/views/DashboardBookingDetailView.vue');$detail=file_get_contents($root.'src/Services/Dashboard/Booking/DashboardBookingDetailService.php');$service=file_get_contents($root.'src/Services/Booking/Program/BookingProgramChangeService.php');
$flat=(string)preg_replace('/\s+/','',$panel);$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
foreach(['AdminContextCard','AdminWizardStepper','expected:expected.value','proposed:proposed.value','OVERRIDE_REQUIRED','PROGRAM_CONFIGURATION_CONFLICT','BookingRuleOverrideDialog','conflicted','reconsider'] as $needle)$assert(str_contains($panel,$needle),"Frontendcontract mist {$needle}.");
$assert(str_contains($flat,'if(submitting.value||props.refreshing||!canSubmit.value)return'),'Dubbele submit/conflictretry wordt niet geblokkeerd.');
$assert(str_contains($view,'await load(true)')&&str_contains($view,'JSON.stringify(booking.education.selections)'),'Detailrefresh of kalendercache-invalidatie ontbreekt.');
$assert(substr_count($view,'booking.education.programLabel')===2,'Onverwachte dubbele statische programmaweergave.');
$assert(str_contains($detail,'BookingProgramConfig::PROGRAMS')&&str_contains($choice,'v-for="option in options"'),'Frontend gebruikt niet de centrale programmalijst.');
$assert(str_contains($service,'withProgram')&&str_contains($service,'REASON_PLANNER_UPDATE')&&!str_contains($service,'Mail'),'Programmaflow reset velden, mist financiële snapshot of verstuurt mail.');
fwrite(STDOUT,"OK: programma frontendcontract geslaagd.\n");
