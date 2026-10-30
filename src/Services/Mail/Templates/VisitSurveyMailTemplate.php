<?php
declare(strict_types=1);

namespace GeoFort\Services\Mail\Templates;

use GeoFort\Utils\DateParser;

final readonly class VisitSurveyMailTemplate
{
    public function __construct(private MailLayout $layout) {}

    public function subject(): string
    {
        return 'Hoe hebben jullie GeoFort ervaren?';
    }

    public function html(string $firstName, string $visitDate, string $url): string
    {
        $date = $this->escape(DateParser::getLongDutchDateFromString($visitDate));
        $link = $this->escape($url);
        $paragraph = MailStyles::paragraph();
        $small = 'margin:0 0 10px 0;font-family:' . MailStyles::FONT_FAMILY . ';font-size:12px;line-height:19px;color:' . MailStyles::COLOR_TEXT . ';';

        return $this->layout->render(
            'Hoe was jullie bezoek aan GeoFort?',
            'Drie korte vragen · ongeveer één minuut',
            '<p style="' . $paragraph . '"><strong>' . $this->escape($this->greeting($firstName)) . '</strong></p>'
            . '<p style="' . $paragraph . '">Wat fijn dat jullie met de klas bij GeoFort waren! We zijn benieuwd hoe jij en de leerlingen het bezoek hebben ervaren.</p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="' . MailStyles::dateHighlightTable() . '"><tr><td style="' . MailStyles::dateHighlightCell() . '">'
            . '<span style="' . MailStyles::dateHighlightLabel() . '">Jullie bezoek</span>'
            . '<span style="' . MailStyles::dateHighlightValue() . '">' . $date . '</span>'
            . '</td></tr></table>'
            . '<p style="' . $paragraph . '">Welke module viel het meest in de smaak? En heb je een tip voor ons? Met jouw reactie help je ons het onderwijsaanbod voor volgende klassen nog beter te maken.</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0;border-collapse:separate;"><tr>'
            . '<td bgcolor="' . MailStyles::COLOR_DARK_BLUE . '" style="background-color:' . MailStyles::COLOR_DARK_BLUE . ';border-radius:6px;text-align:center;">'
            . '<a href="' . $link . '" style="display:inline-block;padding:14px 24px;border:1px solid ' . MailStyles::COLOR_DARK_BLUE . ';border-radius:6px;font-family:' . MailStyles::FONT_FAMILY . ';font-size:16px;line-height:22px;font-weight:700;text-decoration:none;color:' . MailStyles::COLOR_WHITE . ';">Deel jullie ervaring</a>'
            . '</td></tr></table>'
            . '<p style="' . $paragraph . '">Alvast bedankt dat je met ons meedenkt!</p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:22px;border-collapse:collapse;"><tr><td style="padding-top:16px;border-top:1px solid ' . MailStyles::COLOR_BORDER . ';">'
            . '<p style="' . $small . '">Je ontvangt deze eenmalige uitnodiging omdat je bij de aanvraag toestemming gaf. Invullen is vrijwillig. De link is 30 dagen geldig.</p>'
            . '<p style="' . $small . '">We koppelen je antwoord aan jullie bezoek. Het onderwijsteam gebruikt de resultaten intern om het aanbod te verbeteren. Deel geen namen of andere gegevens van leerlingen.</p>'
            . '<p style="' . $small . '">Liever niet meedoen? Via de evaluatie kun je <a href="' . $link . '" style="' . MailStyles::link() . '">je toestemming intrekken</a> en een eerder antwoord verwijderen.</p>'
            . '</td></tr></table>',
        );
    }

    public function text(string $firstName, string $visitDate, string $url): string
    {
        $date = DateParser::getLongDutchDateFromString($visitDate);

        return $this->greeting($firstName) . "\n\n"
            . "Wat fijn dat jullie met de klas bij GeoFort waren! We zijn benieuwd hoe jij en de leerlingen het bezoek hebben ervaren.\n\n"
            . "Jullie bezoek: {$date}\n\n"
            . "Welke module viel het meest in de smaak? En heb je een tip voor ons? Met jouw reactie help je ons het onderwijsaanbod voor volgende klassen nog beter te maken.\n\n"
            . "Drie korte vragen · ongeveer één minuut\nDeel jullie ervaring: {$url}\n\n"
            . "Alvast bedankt dat je met ons meedenkt!\n\nMet vriendelijke groet,\nTeam Onderwijs - GeoFort\n\n"
            . "Je ontvangt deze eenmalige uitnodiging omdat je bij de aanvraag toestemming gaf. Invullen is vrijwillig. De link is 30 dagen geldig.\n\n"
            . "We koppelen je antwoord aan jullie bezoek. Het onderwijsteam gebruikt de resultaten intern om het aanbod te verbeteren. Deel geen namen of andere gegevens van leerlingen.\n\n"
            . "Liever niet meedoen? Via dezelfde link kun je je toestemming intrekken en een eerder antwoord verwijderen.";
    }

    private function greeting(string $firstName): string
    {
        $name = trim($firstName);
        return $name === '' ? 'Beste bezoeker,' : 'Beste ' . $name . ',';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
