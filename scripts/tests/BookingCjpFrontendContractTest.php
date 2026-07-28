<?php

declare(strict_types=1);

$root=dirname(__DIR__,2).'/';
$panel=(string)file_get_contents($root.'resources/js/admin/components/bookings/BookingCjpPanel.vue');
$view=(string)file_get_contents($root.'resources/js/admin/views/DashboardBookingDetailView.vue');
$api=(string)file_get_contents($root.'resources/js/admin/services/dashboardBookingCjpApi.ts');
$service=(string)file_get_contents($root.'src/Services/Booking/Cjp/BookingCjpChangeService.php');
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$assert(str_contains($panel,'<h2>CJP-gegevens</h2>')&&str_contains($panel,'CJP-gegevens wijzigen'),'Aparte CJP-card ontbreekt.');
foreach(['useCjp','contactName','cardNumber']as$field)$assert(str_contains($panel,$field),"CJP-veld {$field} ontbreekt.");
$assert(str_contains($panel,'validateField')&&str_contains($panel,'normalizeCjpPersonName')&&str_contains($panel,'normalizeCjpPasnumber')&&!str_contains($panel,'RegExp'),'Publieke centrale validatie/normalisatie wordt niet gebruikt.');
$assert(str_contains($panel,'v-if="usesCjp"')&&str_contains($panel,'values.contactName=""')&&str_contains($panel,'values.cardNumber=""'),'Conditionele vervolgvelden of direct wissen ontbreken.');
$assert(str_contains($panel,'@blur=')&&str_contains($panel,'validate(["useCjp","contactName","cardNumber"])'),'Blur- of opslagvalidatie ontbreekt.');
$assert(str_contains($panel,'confirmed.value=false')&&str_contains($panel,'if(submitting.value)return'),'Confirmationreset of dubbele-submitpreventie ontbreekt.');
$assert(str_contains($panel,'aria-live="assertive"')&&str_contains($panel,'focusFirst'),'Foutsummary/focuscontract ontbreekt.');
$assert(str_contains($panel,'delete errors.contactName')&&str_contains($panel,'delete errors.cardNumber')&&str_contains($panel,'delete touched.contactName')&&str_contains($panel,'delete touched.cardNumber'),'Fouten of touched-state van verborgen vervolgvelden worden niet gereset.');
$assert(str_contains($panel,"Bij ‘Nee’ worden de ingevulde CJP-contactgegevens en het pasnummer verwijderd."),'Informatieve wisttekst ontbreekt.');
$assert(str_contains($panel,'CJP_CONFLICT')&&str_contains($panel,'Uw invoer is behouden'),'Conflict behoudt proposed invoer niet.');
foreach(['De CJP-gegevens zijn niet gewijzigd.','De CJP-gegevens zijn bijgewerkt.','inmiddels gewijzigd']as$message)$assert(str_contains($panel,$message),"Melding ontbreekt: {$message}");
$assert(str_contains($view,'BookingCjpPanel')&&str_contains($api,'update-booking-cjp.php'),'Card of endpoint ontbreekt.');
$assert(!preg_match('/price|quote|mail/i',$panel.$api.$service),'CJP-mutatie bevat pricing- of mailactie.');
$assert(str_contains($service,'findByIdForUpdate')&&str_contains($service,'insertCjpChange'),'Lock- of auditcontract ontbreekt.');
echo "BookingCjpFrontendContractTest OK\n";
