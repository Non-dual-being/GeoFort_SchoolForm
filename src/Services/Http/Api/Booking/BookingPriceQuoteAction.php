<?php

declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Booking;

use DateTimeImmutable;
use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Services\Booking\Pricing\BookingPriceCalculator;
use GeoFort\Services\Http\Response\JsonResponse;
use GeoFort\Validation\FieldValidationException;
use GeoFort\Validation\FoodAndDrinkSelectionValidator;
use GeoFort\Validation\ProgramSelectionValidator;
use GeoFort\Validation\StudentCountValidator;
use GeoFort\Validation\SupervisorCountValidator;
use Throwable;

final class BookingPriceQuoteAction
{
    public function __construct(
        private readonly JsonResponse $response,
        private readonly BookingPriceCalculator $priceCalculator,
        private readonly ProgramSelectionValidator $programSelectionValidator,
        private readonly StudentCountValidator $studentCountValidator,
        private readonly SupervisorCountValidator $supervisorCountValidator,
        private readonly FoodAndDrinkSelectionValidator $foodAndDrinkSelectionValidator,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public function send(array $payload): void
    {
        try {
            $schoolSector = $this->readRequiredString(
                $payload,
                'onderwijsSector',
                'Kies een geldig onderwijssoort.',
            );

            if (!BookingProgramConfig::isValidSchoolSectorValue($schoolSector)) {
                throw new FieldValidationException(
                    'onderwijsSector',
                    'Kies een geldig onderwijssoort.',
                );
            }

            $visitDate = $this->readVisitDate($payload['bezoekdatum'] ?? null);

            $program = $this->programSelectionValidator->validate(
                program: $this->readRequiredString(
                    $payload,
                    'programma',
                    'Kies een geldig programma.',
                ),
                schoolSector: $schoolSector,
                visitDate: $visitDate,
            );

            $studentCount = $this->studentCountValidator->validate(
                rawValue: $payload['aantalLeerlingen'] ?? null,
                schoolSector: $schoolSector,
                program: $program,
            );

            $supervisorCount = $this->supervisorCountValidator->validate(
                rawValue: $payload['aantalBegeleiders'] ?? null,
                studentCount: $studentCount,
            );

            $foodAndDrinkSelection = $this->foodAndDrinkSelectionValidator->validate($payload);

            $quote = $this->priceCalculator->calculate(
                schoolSector: $schoolSector,
                program: $program,
                studentCount: $studentCount,
                supervisorCount: $supervisorCount,
                foodAndDrinkSelection: $foodAndDrinkSelection,
            );

            $this->response
                ->json([
                    'ok' => true,
                    'data' => $quote->toArray(),
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
                ->serverError('Prijsopgave kan niet worden opgehaald', 500, false)
                ->send();
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function readRequiredString(
        array $payload,
        string $field,
        string $message,
    ): string {
        $value = $payload[$field] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw new FieldValidationException($field, $message);
        }

        return trim($value);
    }

    private function readVisitDate(mixed $rawValue): DateTimeImmutable
    {
        if (!is_string($rawValue) || trim($rawValue) === '') {
            throw new FieldValidationException(
                'bezoekdatum',
                'Kies een geldige bezoekdatum.',
            );
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d', trim($rawValue));
        $errors = DateTimeImmutable::getLastErrors();

        if (
            !$date instanceof DateTimeImmutable
            || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        ) {
            throw new FieldValidationException(
                'bezoekdatum',
                'Kies een geldige bezoekdatum.',
            );
        }

        return $date;
    }
}
