<?php

declare(strict_types=1);

$root=dirname(__DIR__,2).'/';
$panel=(string)file_get_contents($root.'resources/js/admin/components/bookings/BookingSchoolContactPanel.vue');
$view=(string)file_get_contents($root.'resources/js/admin/views/DashboardBookingDetailView.vue');
$api=(string)file_get_contents($root.'resources/js/admin/services/dashboardBookingSchoolContactApi.ts');
$bootstrap=(string)file_get_contents($root.'src/Services/Dashboard/DashboardBootstrapService.php');
$input=(string)file_get_contents($root.'resources/js/admin/components/form/AdminInput.vue');
$controls=(string)file_get_contents($root.'resources/css/admin/controls.css');
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$assert(str_contains($panel,'STEP_IDS=["school-details","contact-review"]'),'Stabiele twee stappen ontbreken.');
$assert(str_contains($panel,'validateField')&&str_contains($panel,'normalizePostcode')&&!preg_match('/\\\\d\\{4\\}/',$panel),'Centrale regels worden niet aantoonbaar hergebruikt.');
$assert(str_contains($panel,'countryOptions')&&str_contains($panel,'AdminSelect'),'Landselectie ontbreekt.');
$assert(str_contains($panel,'aria-live="assertive"')&&str_contains($panel,'focusFirst'),'Foutsummary/focuscontract ontbreekt.');
$assert(str_contains($panel,'confirmed.value=false')&&str_contains($panel,'if(submitting.value)return'),'Confirmationreset of dubbele-submitpreventie ontbreekt.');
$assert(str_contains($panel,'SCHOOL_CONTACT_CONFLICT')&&str_contains($panel,'proposed:{...values}'),'Conflict/proposed contract ontbreekt.');
$assert(substr_count($panel,'updateDashboardBookingSchoolContact(')===1&&str_contains($panel,'step===2'),'API-call of laatste-stapcontract ontbreekt.');
$assert(str_contains($view,'BookingSchoolContactPanel')&&str_contains($view,'schoolContactCompleted'),'Detailcard ontbreekt.');
$assert(str_contains($api,'update-booking-school-contact.php'),'API-endpoint onjuist.');
$assert(str_contains($bootstrap,'update-booking-school-contact'),'CSRF-scope ontbreekt.');
$assert(!str_contains($panel,'sendMail')&&!str_contains($api,'mailMode'),'Frontend start een mailactie.');
$assert(str_contains($input,'admin-control admin-text-input')&&str_contains($input,"'admin-field--disabled':disabled"),'AdminInput mist de gerichte text- of disabledclass.');
$assert(str_contains($controls,'.admin-text-input')&&str_contains($controls,'padding-inline: 0.95rem')&&str_contains($controls,'min-height: 3rem'),'Tekstinvoer heeft onvoldoende projectconforme binnenruimte.');
$assert(str_contains($controls,'.admin-text-input::placeholder')&&str_contains($controls,':-webkit-autofill')&&str_contains($controls,'var(--admin-focus-ring)'),'Placeholder-, autofill- of focuscontract ontbreekt.');
echo "BookingSchoolContactFrontendContractTest OK\n";
