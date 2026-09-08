<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GeoFort\Services\Booking\Data\{BookingRequestData, EducationSelectionData, FoodAndDrinkSelectionData};
use GeoFort\Services\Booking\Presentation\EducationSelectionSummaryFactory;
use GeoFort\Services\Mail\{BookingMailService, MailConfig, MailInterface};
use GeoFort\Services\Mail\Attachments\PublicDocumentAttachmentResolver;
use GeoFort\Services\Mail\Templates\{BookingRequestMailTemplate, MailLayout, MailLinks};
use GeoFort\Validation\Validator;

// No SMTP transport is constructed: production recipients are only captured in memory.
final class RequestBccAuditMailer implements MailInterface
{
    public array $messages = [];
    public bool $failAttachments = false;

    public function send(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody, array $cc = [], array $attachments = [], array $bcc = []): void
    {
        $this->messages[] = compact('toEmail', 'toName', 'subject', 'htmlBody', 'textBody', 'cc', 'attachments', 'bcc');
        if ($this->failAttachments && $attachments !== []) throw new RuntimeException('Simulated attachment failure');
    }
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$links = new MailLinks('https://example.test', 'https://example.test/voorwaarden', 'planner@example.test');
$template = new BookingRequestMailTemplate(new MailLayout($links), $links, new Validator(), new EducationSelectionSummaryFactory());
$request = new BookingRequestData(
    'BCC school', 'Nederland', 'Dijk 1', '1234 AB', 'Plaats', '0345123456', '0612345678',
    'Sanne', 'Jansen', 'sanne@example.test', '2027-03-10', '10 maart 2027', 'Test', 'nee', null, null,
    'primairOnderwijs', 'dag', 'Earth-Watch', 40, 4,
    new EducationSelectionData('primairOnderwijs', ['regulier'], ['regulier' => ['groep5']]),
    FoodAndDrinkSelectionData::fromStoredValues(0, 0, 0, 0, 0, 0, true), null, true,
);
$config = static fn (string $env, array $bcc, ?string $testReceiver = 'test@example.test'): MailConfig => new MailConfig(
    '', 0, '', '', '', 'from@example.test', 'GeoFort', 'planner@example.test', $testReceiver,
    ['cc-one@example.test', 'cc-two@example.test'], $env, bookingRequestBccEmails: $bcc,
);
foreach (['production', 'local', 'dev', 'development', 'test', 'testing', 'staging', ''] as $env) {
    foreach ([[], ['onderwijs@geofort.nl']] as $bcc) {
        $mailer = new RequestBccAuditMailer();
        $service = new BookingMailService($mailer, $config($env, $bcc), $template);
        $service->sendRequestReceivedMail($request);
        $assert(count($mailer->messages) === 1, 'Nieuwe aanvraag verstuurt meer dan een mail.');
        $message = $mailer->messages[0];
        $assert($message['toEmail'] === ($env === 'production' ? $request->email : 'test@example.test'), 'Bestaande ontvanger gewijzigd.');
        $assert($message['cc'] === ['cc-one@example.test', 'cc-two@example.test'], 'Bestaande CC gewijzigd.');
        $assert($message['bcc'] === ($env === 'production' ? $bcc : []), 'Productie-BCC lekt of ontbreekt.');
        $assert($message['subject'] === $template->subject($request) && $message['toName'] === 'Sanne Jansen', 'Mailonderwerp of ontvangernaam gewijzigd.');
        $assert(!str_contains($message['htmlBody'] . $message['textBody'], 'onderwijs@geofort.nl'), 'BCC-adres lekt in mailinhoud.');
    }
}
$mailer = new RequestBccAuditMailer();
(new BookingMailService($mailer, $config('development', ['onderwijs@geofort.nl'], null), $template))->sendRequestReceivedMail($request);
$assert($mailer->messages[0]['toEmail'] === 'planner@example.test' && $mailer->messages[0]['bcc'] === [], 'Plannerfallback gewijzigd.');

// Exercise the existing attachment success and retry paths with the real public document resolver.
foreach ([false, true] as $failAttachments) {
    $mailer = new RequestBccAuditMailer();
    $mailer->failAttachments = $failAttachments;
    $service = new BookingMailService($mailer, $config('production', ['onderwijs@geofort.nl']), $template,
        publicDocumentAttachmentResolver: new PublicDocumentAttachmentResolver(dirname(__DIR__, 2) . '/public'));
    $service->sendRequestReceivedMail($request);
    $assert($mailer->messages[0]['attachments'] !== [], 'Bijlagepad werd niet getest.');
    $assert(count($mailer->messages) === ($failAttachments ? 2 : 1), 'Bestaande bijlageherpoging gewijzigd.');
    foreach ($mailer->messages as $message) {
        $assert($message['bcc'] === ['onderwijs@geofort.nl'] && $message['toEmail'] === $request->email, 'BCC/ontvanger ontbreekt bij bijlagepad.');
    }
}
echo "OK: aanvraag-BCC, bestaande ontvangers/CC, alle omgevingen, een verzending en bijlagefallback; geen echte mail.\n";
