<?php
declare(strict_types=1);
namespace GeoFort\Services\Http\Api\Booking;

use GeoFort\Services\Http\Response\JsonResponse;

use GeoFort\Services\Booking\Data\BookingRequestData;
use GeoFort\Services\Booking\Submission\BookingSubmissionService;
use GeoFort\Services\Booking\Availability\BookingAvailabilityService;

use GeoFort\Services\Sql\FormSubmitLogService;



use GeoFort\Validation\FieldValidationException;
use GeoFort\Validation\FormRules;
use GeoFort\Validation\Validator;

use GeoFort\Utils\DateParser;

use Throwable;

final class BookingFormHandler
{
    private readonly DateParser $dateParser;

    public function __construct(
        private readonly JsonResponse $response,
        private readonly Validator $validator,
        private readonly FormSubmitLogService $formSubmitSqlLogService,
        private readonly BookingAvailabilityService $bookingAvailabilityService,
        private readonly BookingSubmissionService $bookingSubmissionService,
        private readonly string $ip,
        private readonly int $cooldownSeconds = 30,
    ) {
        $this->dateParser = new DateParser();
    }

    public function handle(array $postData): void
    {
        try {
            $schoolnaam = $this->validator->text(
                'schoolnaam',
                $postData['schoolnaam'] ?? '',
                FormRules::RULES
            );

            $land = $this->validator->text(
                'land',
                $postData['land'] ?? '',
                FormRules::RULES
            );


            $postcode = $this->validator->postcode(
                $land,
                $postData['postcode'] ?? '',
                FormRules::RULES['postcode']
            );

            
            $adres = $this->validator->text(
                'adres',
                $postData['adres'] ?? '',
                FormRules::RULES
            );

            $plaats = $this->validator->text(
                'plaats',
                $postData['plaats'] ?? '',
                FormRules::RULES
            );

            $schoolTelefoonnummer = $this->validator->phone(
                'schoolTelefoonnummer',
                $land,
                $postData['schoolTelefoonnummer'] ?? '',
                FormRules::RULES['schoolTelefoonnummer']
            );

            $contactpersoonTelefoonnummer = $this->validator->phone(
                'contactpersoonTelefoonnummer',
                $land,
                $postData['contactpersoonTelefoonnummer'] ?? '',
                FormRules::RULES['contactpersoonTelefoonnummer']
            );

            $contactpersoonVoornaam = $this->validator->text(
                'contactpersoonVoornaam',
                $postData['contactpersoonVoornaam'] ?? '',
                FormRules::RULES
            );

            $contactpersoonAchternaam = $this->validator->text(
                'contactpersoonAchternaam',
                $postData['contactpersoonAchternaam'] ?? '',
                FormRules::RULES
            );

            $email = $this->validator->email(
                field: 'email',
                value: $postData['email'] ?? '',
                rules: FormRules::RULES
            );

            $visitDate = $this->validator->date(
                field: 'bezoekdatum',
                value: $postData['bezoekdatum'] ?? '',
            );

            $availableVisitDate = $this->bookingAvailabilityService->assertDateIsValid($visitDate);
            $visitStringDate = $this->dateParser::getLongDutchDate($availableVisitDate);

            $hoeKentUGeoFort = $this->validator->discovery(
                field: 'hoeKentUGeoFort',
                value: $postData['hoeKentUGeoFort'] ?? '',
                rules: FormRules::RULES
            );

            $cjpPasGebruik = $this->validator->text(
                'cjpPasGebruik',
                $postData['cjpPasGebruik'] ?? '',
                FormRules::RULES
            );

            $cjpContactpersoonNaam = null;
            $cjpPasnummer =  null;

            if ($cjpPasGebruik === "ja") {
                $cjpContactpersoonNaam = $this->validator->text(
                    'cjpContactpersoonNaam',
                    $postData['cjpContactpersoonNaam'] ?? '',
                    FormRules::RULES
                );

                $cjpPasnummer = $this->validator->text(
                    'cjpPasnummer',
                    $postData['cjpPasnummer'] ?? '',
                    FormRules::RULES
                );
            } else {
                $cjpFieldsSend = (bool) (
                    (isset ($postData['cjpContactpersoonNaam']))
                        ||
                    (isset($postData['cjpPasnummer']))
                );

                if ($cjpFieldsSend) 
                    throw new FieldValidationException(
                        'cjpPasGebruik',
                        "Het pasnummer of de cjpvoornaam kunnen pas meeverzonden worden als u de korting wilt gebruiken"
                    );
            }
             
            $remaining = $this->formSubmitSqlLogService->getCoolDownRemaining(
                $this->ip,
                $this->cooldownSeconds
            );

            if ($remaining > 0) {
                $this->response->rateLimited($remaining)->send();
                return;
            }

           $request = new BookingRequestData(
                schoolnaam: $schoolnaam,
                land: $land,
                adres: $adres,
                postcode: $postcode,
                plaats: $plaats,
                schoolTelefoonnummer: $schoolTelefoonnummer,
                contactpersoonTelefoonnummer: $contactpersoonTelefoonnummer,
                contactpersoonVoornaam: $contactpersoonVoornaam,
                contactpersoonAchternaam: $contactpersoonAchternaam,
                email: $email,
                bezoekdatum: $visitStringDate,
                hoeKentUGeoFort: $hoeKentUGeoFort,
                cjpPasGebruik: $cjpPasGebruik,
                cjpContactpersoonNaam: $cjpContactpersoonNaam,
                cjpPasnummer: $cjpPasnummer,
            );

            $this->bookingSubmissionService->submit($request, $this->ip);

            $this->response
                ->ok()
                ->send();
        } catch (FieldValidationException $e) {
            $this->response
                ->validationError([
                    $e->getField() => $e->getMessage(),
                ])
                ->send();
        } catch (Throwable $e) {
            error_log(__METHOD__ . ' : ' . $e->getMessage());

            $this->response
                ->serverError('Aanvraag kon niet worden verwerkt.', 500, false)
                ->send();
        }
    }
}