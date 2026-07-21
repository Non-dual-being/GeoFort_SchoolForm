<?php

declare(strict_types=1);

use GeoFort\Booking\BookingPolicy;
use GeoFort\Services\Booking\Data\BookingRequestData;
use GeoFort\Services\Booking\Data\EducationSelectionData;
use GeoFort\Services\Booking\Data\FoodAndDrinkSelectionData;
use GeoFort\Services\Booking\Presentation\EducationSelectionSummaryFactory;
use GeoFort\Services\Booking\Pricing\BookingPriceCalculator;
use GeoFort\Services\Mail\Templates\BookingRequestMailTemplate;
use GeoFort\Services\Mail\Templates\MailLayout;
use GeoFort\Services\Mail\Templates\MailLinks;
use GeoFort\Validation\Validator;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$links = new MailLinks(
    baseUrl: 'https://onderwijsformulier.test',
    voorwaardenUrl: 'https://onderwijsformulier.test/booking/voorwaarden.php',
    onderwijsEmail: 'onderwijs@geofort.nl',
);
$template = new BookingRequestMailTemplate(
    new MailLayout($links),
    $links,
    new Validator(),
    new EducationSelectionSummaryFactory(),
);
$request = new BookingRequestData(
    'De Testschool',
    'Nederland',
    'Teststraat 1',
    '1234 AB',
    'Teststad',
    '0345123456',
    '0612345678',
    'Sanne',
    'Jansen',
    'sanne@example.test',
    '2026-09-23',
    'woensdag 23 september 2026',
    'Via school',
    'nee',
    null,
    null,
    'primairOnderwijs',
    BookingPolicy::PROGRAM_DAY,
    null,
    31,
    4,
    new EducationSelectionData(
        'primairOnderwijs',
        ['regulier'],
        ['regulier' => ['groep5', 'groep6']],
    ),
    new FoodAndDrinkSelectionData(3, 2, 1, 0, 0, 'eigenPicknick', 0, true),
    "Eerste regel\nTweede regel",
    true,
);
$quote = (new BookingPriceCalculator())->calculate(
    $request->schoolSector,
    $request->programma,
    $request->aantalLeerlingen,
    $request->aantalBegeleiders,
    $request->foodAndDrinkSelection,
);
$rosterText = 'In de bijlage treft u het conceptrooster aan.';
$routeText = 'Daarnaast vindt u in de bijlage route- en parkeerinformatie.';

$requestHtml = $template->html($request, $rosterText, $quote, $routeText);
$requestText = $template->text($request, $rosterText, $quote, $routeText);
$requestCombined = $requestHtml . "\n" . $requestText;

$assert(str_contains($requestHtml, 'Beste Sanne,'), 'HTML-aanvraag mist persoonlijke aanhef.');
$assert(str_contains($requestText, 'Beste Sanne,'), 'Tekstaanvraag mist persoonlijke aanhef.');
$assert(str_contains($requestCombined, $request->bezoekdatumLabel), 'Aanvraag mist actuele bezoekdatum.');
$assert(str_contains($requestCombined, 'nog niet definitief'), 'Aanvraag mist voorlopige status.');
$assert(!str_contains($requestCombined, 'Uw schoolbezoek aan GeoFort is definitief bevestigd.'), 'Aanvraag bevat definitieve bevestiging.');
$assert(!str_contains(strtolower($requestCombined), 'gekozen datum niet beschikbaar'), 'Aanvraag bevat afwijzingstekst.');
$assert(str_contains($requestHtml, $links->voorwaardenUrl), 'HTML-aanvraag mist voorwaardenlink.');
$assert(str_contains($requestHtml, $links->websiteUrl), 'HTML-aanvraag mist GeoFort-link.');
$assert(str_contains($requestText, 'Aantal leerlingen: 31'), 'Tekstaanvraag mist leerlingenaantal.');
$assert(str_contains($requestHtml, 'Aantal begeleiders'), 'HTML-aanvraag mist begeleidersveld.');

$confirmationHtml = $template->confirmationHtml($request, $rosterText, $quote, $routeText);
$confirmationText = $template->confirmationText($request, $rosterText, $quote, $routeText);
$confirmationCombined = $confirmationHtml . "\n" . $confirmationText;

$assert(str_contains($confirmationHtml, 'Beste Sanne,'), 'HTML-bevestiging mist persoonlijke aanhef.');
$assert(str_contains($confirmationText, 'Beste Sanne,'), 'Tekstbevestiging mist persoonlijke aanhef.');
$assert(str_contains($confirmationCombined, $request->bezoekdatumLabel), 'Bevestiging mist actuele bezoekdatum.');
$assert(str_contains($confirmationCombined, 'definitief bevestigd'), 'Bevestiging mist definitieve status.');
$assert(!str_contains($confirmationCombined, 'nog niet definitief'), 'Bevestiging bevat voorlopige formulering.');
$assert(str_contains($confirmationCombined, 'Aantal leerlingen: 31'), 'Bevestiging mist actueel leerlingenaantal.');
$assert(str_contains($confirmationCombined, 'Aantal begeleiders: 4'), 'Bevestiging mist actueel begeleidersaantal.');
$assert(substr_count($confirmationHtml, 'Kostenoverzicht') === 1, 'HTML-bevestiging bevat niet exact één prijsquote.');
$assert(substr_count($confirmationText, 'Kostenoverzicht') === 1, 'Tekstbevestiging bevat niet exact één prijsquote.');
$assert(str_contains($confirmationCombined, 'Bestelde eten en drinken'), 'Bevestiging mist catering.');
$assert(str_contains($confirmationCombined, $rosterText), 'Bevestiging mist roostertekst.');
$assert(str_contains($confirmationCombined, $routeText), 'Bevestiging mist routetekst.');
$assert(str_contains($confirmationHtml, $links->voorwaardenUrl), 'HTML-bevestiging mist voorwaardenlink.');
$assert(str_contains($confirmationHtml, $links->websiteUrl), 'HTML-bevestiging mist GeoFort-link.');
$assert(str_contains($confirmationHtml, 'Eerste regel<br>'), 'HTML escaping/multiline-opmaak is gewijzigd.');
$assert(str_contains($confirmationText, "Eerste regel\nTweede regel"), 'Tekstbevestiging mist multiline-opmerking.');

fwrite(STDOUT, "OK: normale aanvraagmail en definitieve bevestigingsmail gecontroleerd.\n");
