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
use GeoFort\Validation\EducationSelectionValidator;
use GeoFort\Validation\ProgramSelectionValidator;
use GeoFort\Validation\ChoiceModuleSelectionValidator;
use GeoFort\Validation\StudentCountValidator;
use GeoFort\Validation\SupervisorCountValidator;
use GeoFort\Validation\FoodAndDrinkSelectionValidator;
use GeoFort\Validation\TermsAcceptanceValidator;


use GeoFort\Utils\DateParser;

use Throwable;

final class BookingFormHandler
{
    private readonly DateParser $dateParser;

    public function __construct(
        private readonly JsonResponse $response,
        private readonly Validator $validator,
        private readonly EducationSelectionValidator $educationSelectionValidator,
        private readonly FormSubmitLogService $formSubmitSqlLogService,
        private readonly BookingAvailabilityService $bookingAvailabilityService,
        private readonly BookingSubmissionService $bookingSubmissionService,
        private readonly ProgramSelectionValidator $programSelectionValidator,
        private readonly ChoiceModuleSelectionValidator $choiceModuleSelectionValidator,
        private readonly StudentCountValidator $studentCountValidator,
        private readonly SupervisorCountValidator $supervisorCountValidator,
        private readonly FoodAndDrinkSelectionValidator $foodAndDrinkSelectionValidator,
        private readonly TermsAcceptanceValidator $termsAcceptanceValidator,
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
            $visitDateYmd = $availableVisitDate->format('Y-m-d');
            $visitDateLabel = $this->dateParser::getLongDutchDate($availableVisitDate);

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

            $schoolSector = $this->validator->schoolSector(
                'onderwijsSector',
                $postData['onderwijsSector'] ?? '',
                FormRules::RULES
            );

            /**
             * Eerst simpele veldvalidatie:
             * - bestaat het veld?
             * - is het ochtend of dag?
             */
            $programRaw = $this->validator->text(
                'programma',
                $postData['programma'] ?? '',
                FormRules::RULES
            );

            /**
             * Daarna domeinvalidatie:
             * - mag dit programma bij de gekozen sector?
             * - mag dit programma op deze bezoekdatum?
             */
            $programma = $this->programSelectionValidator->validate(
                $programRaw,
                $schoolSector,
                $availableVisitDate,
            );

            $educationSelection = $this->educationSelectionValidator->validate(
                $postData['educationSelection'] ?? '',
                $schoolSector,
            );

            $keuzemoduleKey = $this->choiceModuleSelectionValidator->validate(
                rawValue: $postData['keuzemodule'] ?? null,
                schoolSector: $schoolSector,
                program: $programma,
                educationSelection: $educationSelection,
            );

            $aantalLeerlingen = $this->studentCountValidator->validate(
                rawValue: $postData['aantalLeerlingen'] ?? '',
                schoolSector: $schoolSector,
                program: $programma,
            );

            $aantalBegeleiders = $this->supervisorCountValidator->validate(
                rawValue: $postData['aantalBegeleiders'] ?? '',
                studentCount: $aantalLeerlingen,
            );

            $foodAndDrinkSelection = $this->foodAndDrinkSelectionValidator->validate($postData);
            $opmerkingen = $this->validator->optionalMultilineText(
                field: 'opmerkingen',
                value: $postData['opmerkingen'] ?? '',
                rules: FormRules::RULES['opmerkingen'],
            );
            $voorwaardenAkkoord = $this->termsAcceptanceValidator->validate(
                $postData['voorwaardenAkkoord'] ?? null,
            );

            $this->bookingAvailabilityService->assertCapacityAvailable(
                visitDate: $availableVisitDate,
                requestedStudents: $aantalLeerlingen,
                program: $programma,
            );

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
                bezoekdatum: $visitDateYmd,
                bezoekdatumLabel: $visitDateLabel,
                hoeKentUGeoFort: $hoeKentUGeoFort,
                cjpPasGebruik: $cjpPasGebruik,
                cjpContactpersoonNaam: $cjpContactpersoonNaam,
                cjpPasnummer: $cjpPasnummer,
                schoolSector: $schoolSector,
                programma: $programma,
                keuzemoduleKey: $keuzemoduleKey,
                aantalLeerlingen: $aantalLeerlingen,
                aantalBegeleiders: $aantalBegeleiders,
                educationSelection: $educationSelection,
                foodAndDrinkSelection: $foodAndDrinkSelection,
                opmerkingen: $opmerkingen,
                voorwaardenAkkoord: $voorwaardenAkkoord,
            );
            
            $submission = $this->bookingSubmissionService->submit($request, $this->ip);

            $this->response
                ->json([
                    'ok' => true,
                    'mailDelivery' => $submission->mailSent ? 'sent' : 'failed',
                ])
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
