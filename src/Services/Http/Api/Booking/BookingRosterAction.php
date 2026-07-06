<?php

declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Booking;

use DateTimeImmutable;
use GeoFort\Services\Booking\Roster\BookingRosterResolver;
use GeoFort\Services\Http\Response\JsonResponse;
use GeoFort\Validation\ChoiceModuleSelectionValidator;
use GeoFort\Validation\EducationSelectionValidator;
use GeoFort\Validation\FieldValidationException;
use GeoFort\Validation\ProgramSelectionValidator;
use GeoFort\Validation\StudentCountValidator;
use GeoFort\Validation\SupervisorCountValidator;
use Throwable;

final class BookingRosterAction
{
    public function __construct(
        private readonly JsonResponse $response,
        private readonly BookingRosterResolver $bookingRosterResolver,
        private readonly ProgramSelectionValidator $programSelectionValidator,
        private readonly EducationSelectionValidator $educationSelectionValidator,
        private readonly ChoiceModuleSelectionValidator $choiceModuleSelectionValidator,
        private readonly StudentCountValidator $studentCountValidator,
        private readonly SupervisorCountValidator $supervisorCountValidator,
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

            $educationSelection = $this->educationSelectionValidator->validate(
                rawValue: $payload['educationSelection'] ?? null,
                expectedSector: $schoolSector,
            );

            $choiceModule = $this->choiceModuleSelectionValidator->validate(
                rawValue: $payload['keuzemodule'] ?? '',
                schoolSector: $schoolSector,
                program: $program,
                educationSelection: $educationSelection,
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

            $result = $this->bookingRosterResolver->resolve(
                schoolSector: $schoolSector,
                program: $program,
                educationSelection: $educationSelection,
                choiceModuleKey: $choiceModule,
                studentCount: $studentCount,
                supervisorCount: $supervisorCount,
            );

            $this->response
                ->json([
                    'ok' => true,
                    'data' => $result->toArray(),
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
                ->serverError('Conceptrooster kan niet worden opgehaald', 500, false)
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
