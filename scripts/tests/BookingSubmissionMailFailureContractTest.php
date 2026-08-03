<?php

declare(strict_types=1);

$root=dirname(__DIR__,2);$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$service=(string)file_get_contents($root.'/src/Services/Booking/Submission/BookingSubmissionService.php');
$handler=(string)file_get_contents($root.'/src/Services/Http/Api/Booking/BookingFormHandler.php');
$types=(string)file_get_contents($root.'/resources/js/types/http/ApiResponse.ts');
$form=(string)file_get_contents($root.'/resources/js/views/BookingGeoFormView.vue');
$success=(string)file_get_contents($root.'/resources/js/views/BookingSuccessView.vue');
$submit=(string)file_get_contents($root.'/resources/js/composables/useFormSubmit.ts');
$button=(string)file_get_contents($root.'/resources/js/components/form/GeoFormSubmitButton.vue');
$assert(strpos($service,'->commit()')<strpos($service,'sendRequestReceivedMail')&&str_contains($service,'return new BookingSubmissionResult($mailSent)'),'Service rapporteert mailstatus niet veilig na commit.');
$assert(str_contains($handler,"'ok' => true")&&str_contains($handler,"'mailDelivery' => \$submission->mailSent ? 'sent' : 'failed'"),'Endpoint onderscheidt mailfalen niet binnen een succesvolle aanvraagresponse.');
$assert(str_contains($types,'mailDelivery: "sent" | "failed"')&&str_contains($form,'emit("success", result)'),'Frontendcontract verliest de mailstatus.');
$assert(str_contains($success,"mailDelivery === 'sent'")&&str_contains($success,'Uw aanvraag is succesvol ontvangen en opgeslagen.')&&str_contains($success,'U hoeft de aanvraag niet opnieuw te versturen.'),'Succespagina legt mailfalen niet veilig uit.');
$assert(str_contains($submit,'state.value = "success"')&&str_contains($button,'["pending", "slow", "success"].includes(props.state)'),'Succes met mailfalen maakt submitknop opnieuw beschikbaar.');
$assert(!str_contains($success,'SMTP')&&!str_contains($handler,'mailException'),'Frontend/response lekt interne maildetails.');
echo "Booking submission mail failure response contract tests passed.\n";
