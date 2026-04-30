<?php
namespace GeoFort\Services\Mail\Templates;

use GeoFort\Services\Booking\BookingRequestData;

final readonly class BookingRequestMailTemplate
{
    public function subject(): string 
    {
        return 'Bevestiging aanvraag schoolbezoek GeoFort';
    }

    public function html(BookingRequestData $request): string {
        $schoolnaam = htmlspecialchars(
            $request->schoolnaam,
            ENT_QUOTES,
            'UTF-8'

        );

        return "
            <!DOCTYPE html>
            <html lang='nl'>
            <head>
                <meta charset='UTF-8'>
                <title>Bevestiging aanvraag</title>
            </head>
            <body style='font-family: Arial, sans-serif;'>
                <h1>GeoFort Onderwijs</h1>

                <p>Beste aanvrager,</p>

                <p>
                    We hebben je aanvraag goed ontvangen voor:
                    <strong>{$schoolnaam}</strong>.
                </p>

                <p>
                    Dit is voorlopig een testmail vanuit de nieuwe Vue/PHP-flow.
                </p>

                <p>
                    Met vriendelijke groet,<br>
                    Team Onderwijs - GeoFort
                </p>
            </body>
            </html>
        ";
    }
}