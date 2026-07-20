<?php

declare(strict_types=1);

use GeoFort\Services\Booking\Data\BookingRequestData;
use GeoFort\Booking\BookingPolicy;
use GeoFort\Services\Booking\Data\EducationSelectionData;
use GeoFort\Services\Booking\Data\FoodAndDrinkSelectionData;
use GeoFort\Services\Booking\Presentation\EducationSelectionSummaryFactory;
use GeoFort\Services\Booking\Pricing\BookingPriceCalculator;
use GeoFort\Services\Mail\Templates\BookingRejectionMailTemplate;
use GeoFort\Services\Mail\Templates\BookingRequestMailTemplate;
use GeoFort\Services\Mail\Templates\MailLayout;
use GeoFort\Services\Mail\Templates\MailLinks;
use GeoFort\Validation\Validator;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$links = new MailLinks(
    'https://onderwijsformulier.test',
    'https://onderwijsformulier.test/booking/voorwaarden.php',
    'onderwijs@geofort.nl',
);
$layout = new MailLayout($links);
$request = new BookingRequestData(
    'De Testschool', 'Nederland', 'Teststraat 1', '1234 AB', 'Teststad', '0345123456',
    '0612345678', 'Sanne', 'Jansen', 'sanne@example.test', '2026-09-23',
    'woensdag 23 september 2026', 'Via school', 'nee', null, null,
    'primairOnderwijs', BookingPolicy::PROGRAM_DAY, null, 30, 3,
    new EducationSelectionData('primairOnderwijs', ['regulier'], ['regulier' => ['groep5']]),
    new FoodAndDrinkSelectionData(0, 0, 0, 0, 0, 'eigenPicknick', 0, true),
    null, true,
);

$rejection = new BookingRejectionMailTemplate($layout, $links);
$subject = $rejection->subject($request);
$html = $rejection->html($request);
$text = $rejection->text($request);
$combined = $html . "\n" . $text;

$assert($subject === 'Helaas is de gekozen datum voor jullie schoolbezoek niet beschikbaar', 'Onderwerpregel wijkt af.');
$assert(str_contains($combined, 'Beste Sanne,'), 'Voornaam ontbreekt.');
$assert(str_contains($combined, 'woensdag 23 september 2026'), 'Bezoekdatum ontbreekt.');
$assert(str_contains($combined, 'gekozen datum niet beschikbaar'), 'Kernboodschap ontbreekt.');
$assert(str_contains($combined, 'alternatieve datum'), 'Uitnodiging voor alternatieve datum ontbreekt.');
$assert(!str_contains(strtolower($combined), 'totaalprijs'), 'Afwijzingsmail bevat totaalprijs.');
$assert(!str_contains($combined, 'Uw schoolbezoek aan GeoFort is definitief bevestigd'), 'Afwijzingsmail bevat bevestigingstekst.');
$assert(!str_contains(strtolower($combined), 'afgewezen'), 'Afwijzingsmail zegt dat school of aanvraag is afgewezen.');
foreach (['https://www.geofort.nl/onderwijs/lesmodules/', 'https://www.gogeo.nl/lesmodules/', 'https://workshops.geocraft.nl/', $links->voorwaardenUrl, 'mailto:' . $links->onderwijsEmail] as $url) {
    $assert(str_contains($combined, $url), "Link ontbreekt: {$url}");
}

$requestTemplate = new BookingRequestMailTemplate($layout, $links, new Validator(), new EducationSelectionSummaryFactory());
$requestHtml = $requestTemplate->html($request);
$assert(str_contains($requestHtml, 'nog niet definitief'), 'Normale aanvraagmail is niet meer voorlopig geformuleerd.');
$quote = (new BookingPriceCalculator())->calculate(
    $request->schoolSector, $request->programma, $request->aantalLeerlingen,
    $request->aantalBegeleiders, $request->foodAndDrinkSelection,
);
$confirmationHtml = $requestTemplate->confirmationHtml($request, null, $quote, null);
$assert(str_contains($confirmationHtml, 'Uw schoolbezoek aan GeoFort is definitief bevestigd.'), 'Definitieve bevestigingsmail is inhoudelijk gewijzigd.');

fwrite(STDOUT, "OK: afwijzingsmailtemplate en ongewijzigde aanvraag-/bevestigingsflows gecontroleerd.\n");
