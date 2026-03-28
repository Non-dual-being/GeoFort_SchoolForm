<?php
declare(strict_types=1);
namespace GeoFort\Validation;

final class Validator
{
    /**
     * @param array<string, mixed> $rules
     */
    public function text(string $field, mixed $value, array $rules): string
    {
        if (!array_key_exists($field, $rules)) {
            throw new \InvalidArgumentException(
                "Geen validatieregel gedefinieerd voor veld '$field'."
            );
        }

        $config = $rules[$field];
        $required = (bool) ($config['required'] ?? false);
        $min = (int) ($config['min'] ?? 0);
        $max = (int) ($config['max'] ?? 255);
        $regex = (string) ($config['regex'] ?? '');

        $raw = is_string($value) ? trim($value) : '';

        if ($required && $raw === '') {
            throw new FieldValidationException(
                $field,
                ucfirst($field) . ' is verplicht.'
            );
        }

        if (!$required && $raw === '') {
            return '';
        }

        $length = mb_strlen($raw);

        if ($length < $min || $length > $max) {
            throw new FieldValidationException(
                $field,
                ucfirst($field) . " moet tussen $min en $max tekens bevatten."
            );
        }

        if ($regex !== '' && preg_match($regex, $raw) !== 1) {
            throw new FieldValidationException(
                $field,
                "Ongeldige invoer voor $field."
            );
        }

        return $raw;
    }

    /**
     * @param array<string, mixed> $rules
     */
    public function postcode(string $field, mixed $value, array $rules): string
    {
        $raw = is_string($value) ? trim($value) : '';
        $normalized = strtoupper(preg_replace('/\s+/', '', $raw) ?? '');

        return $this->text($field, $normalized, $rules);
    }

    public function int(
        string $field,
        mixed $value,
        int $min,
        int $max
    ): int {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new FieldValidationException($field, 'Ongeldig getal.');
        }

        $number = (int) $value;

        if ($number < $min || $number > $max) {
            throw new FieldValidationException(
                $field,
                "Waarde moet tussen $min en $max liggen."
            );
        }

        return $number;
    }
}

?>