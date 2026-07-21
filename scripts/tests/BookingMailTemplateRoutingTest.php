<?php

declare(strict_types=1);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$root = dirname(__DIR__, 2);
$publicMailService = file_get_contents($root . '/src/Services/Mail/BookingMailService.php');
$statusMailSender = file_get_contents($root . '/src/Services/Mail/BookingStatusMailSender.php');

$assert(is_string($publicMailService), 'Publieke mailservice kon niet worden gelezen.');
$assert(is_string($statusMailSender), 'Statusmailsender kon niet worden gelezen.');

$assert(
    str_contains($publicMailService, '$this->template->html(')
        && str_contains($publicMailService, '$this->template->text('),
    'Publieke aanvraagflow gebruikt niet de normale aanvraagtemplate-methodes.',
);
$assert(
    !str_contains($publicMailService, '$this->template->confirmationHtml(')
        && !str_contains($publicMailService, '$this->template->confirmationText('),
    'Publieke aanvraagflow gebruikt per ongeluk de definitieve bevestiging.',
);
$assert(
    str_contains($statusMailSender, '$this->confirmationTemplate->confirmationHtml(')
        && str_contains($statusMailSender, '$this->confirmationTemplate->confirmationText('),
    'sendConfirmation gebruikt niet de definitieve template-methodes.',
);
$assert(
    str_contains($statusMailSender, '$this->rejectionTemplate->html(')
        && str_contains($statusMailSender, '$this->rejectionTemplate->text('),
    'sendRejection gebruikt niet de afwijzingstemplate.',
);

fwrite(STDOUT, "OK: publieke aanvraag- en statusmailtemplaterouting gecontroleerd.\n");
