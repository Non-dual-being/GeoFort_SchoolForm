<?php
declare(strict_types=1);
namespace GeoFort\Validation;

use DateTimeImmutable;
use GeoFort\Booking\BookingProgramConfig;

final class Validator
{
    /**
     * @param array<string, mixed> $rules
     */
    public function textByRule(string $field, mixed $value, array $rules): string
    {
        $config = $rules;
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

    public function text(string $field, mixed $value, array $rules){
        if (!array_key_exists($field, $rules)) {
            throw new \InvalidArgumentException(
                "Geen validatieregel gedefinieerd voor veld '$field'."
            );
        }

        return $this->textByRule($field, $value, $rules[$field]);
    }

    /**
     * @param array<string, mixed> $rules
     */
    public function postcode(string $country, mixed $value, array $rules): string
    {
        /**rules is already index on postcode in the formhandler */
        if (!array_key_exists($country, $rules)){
            throw new FieldValidationException(
                'land',
                'Kies een geldig land'
            );
        }

        $raw = is_string($value) ? trim($value) : '';
        if ($country === 'Nederland'){
            $normalized = strtoupper(preg_replace('/\s+/', '', $raw) ?? '');

            if (strlen($normalized) === 6) {
                $normalized = substr($normalized, 0 , 4) . " " . substr($normalized, 4);
            }

            return $this->textByRule('postcode', $normalized, $rules[$country]);
        }
        
        return $this->textByRule('postcode', $raw, $rules[$country]);
    }

    public function email(string $field, mixed $value, array $rules): string {
        $raw = is_string($value) ? trim($value) : '';
        if ($raw === '') {
            throw new FieldValidationException(
                $field,
                'Ongeldig of geen email adres doorgegeven'
            );
        }

        $normalized = str_ireplace("\u{00A0}", ' ', $raw);

        $validEmail = $this->isValidEmail($normalized);

        if (!$validEmail) throw new FieldValidationException(
            $field,
            'Ongeldige email opgegeven'
        );

        return $this->text($field, $normalized, $rules);

    }

    
    public function date(string $field, mixed $value): string {
        $raw = is_string($value) ? trim($value) : '';

        if ($raw === '') throw new FieldValidationException(
            $field,
            'Kies een bezoekdatum',
        );

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

        /**
         * Dat uitroepteken ! reset de niet-meegegeven tijdsdelen naar een vaste basis.
         */

        if (!$date || $date->format('Y-m-d') !== $raw) {
            throw new FieldValidationException(
                $field,
                'Ongeldige bezoekdatum'
            );
        }

        return $raw;

    }

    public function phone(string $field, string $country, mixed $value, array $rules): string {
        $raw = is_string($value) ? trim($value) : '';

        if (($rules['required'] ?? true) && ($raw === '')){
            throw new FieldValidationException(
                $field,
                'Dit veld moet ingevuld worden'
            );
        }

        $normalized = $this->normalizePhonenumber($raw);
        $length = mb_strlen($normalized);

        if (!FormRules::isAllowedCountry($country)){
            throw new FieldValidationException(
                $field,
                sprintf('%s is een ongeldig land', $country)
            );
        }

        $rules = $rules[$country];

        if ($length < $rules['min']) {
                throw new FieldValidationException(
                    $field,
                    sprintf(
                        'Het telefoonnummer moet minimaal %d tekens bevatten.',
                        $rules['min']
                    )
                );
            }

        if ($length > $rules['max']) {
            throw new FieldValidationException(
                $field,
                sprintf(
                    'Het telefoonnummer mag maximaal %d tekens bevatten.',
                    $rules['max']
                )
            );
        }

        $digitCount = preg_match_all('/\d/', $normalized);

        if (
        isset($rules['minDigits'])
        && $digitCount < $rules['minDigits']
        ) {
            throw new FieldValidationException(
                $field,
                sprintf(
                    'Het telefoonnummer moet minimaal %d cijfers bevatten.',
                    $rules['minDigits']
                )
            );
        }

        if (
            isset($rules['maxDigits'])
            && $digitCount > $rules['maxDigits']
        ) {
            throw new FieldValidationException(
                $field,
                sprintf(
                    'Het telefoonnummer mag maximaal %d cijfers bevatten.',
                    $rules['maxDigits']
                )
            );
        }

        if (!preg_match($rules['regex'], $normalized)) {
            throw new FieldValidationException(
                $field,
                'Gebruik een geldig Nederlands of Belgisch telefoonnummer, bijvoorbeeld 06 12345678, +31 6 12345678 of +32 4 12 34 56 78.'
            );
        }

        return $normalized;
        
    }

    public function discovery(
        string $field,
        mixed $value,
        array $rules
    ): string {
        if (!array_key_exists($field, $rules))
            throw new \InvalidArgumentException("Ontbrekende validatie regel voor $field");

        $config = $rules[$field];

        $required       = (bool) ($config['required'] ?? false);
        $allowedValues  = (array) $config['allowedValues'] ?? [];
        $otherOption    = (string) ($config['otherOption'] ?? '');
        $customMin      = (int) ($config['customMin'] ?? 2);
        $customMax      = (int) ($config['customMax'] ?? 80);
        $regex          = (string) ($config['regex'] ?? '');


        $otherOptionValidateString = FormRules::GEOFORT_DISCOVERY_OTHER_OPTION_VALIDATE_VALUE;

        if (!is_array($allowedValues) || $allowedValues === [])
            throw new \InvalidArgumentException("Onvolledige validatie regels voor $field");

        if ($otherOption === '') 
            throw new \InvalidArgumentException("Validatieregel $field mist otherOption");

        if ($regex === '')
            throw new \InvalidArgumentException("Mist regexregel voor veld: $field");

        $raw = is_string($value) 
            ? $this->normalizeDiscoveryValue($value) 
            : null;
        
        if (!isset($raw))
            throw new \InvalidArgumentException("Ongeldige waarde voor $field");

        if ($raw === '') {
            if ($required) throw new FieldValidationException("$field is een verplicht veld, kies een optie uit de lijst");
            return "";
        }

        if (in_array($raw, $allowedValues, true)) {
            return $raw;
        }

        if (!str_starts_with($raw, $otherOptionValidateString)) 
            throw new FieldValidationException("Kies een geldig optie uit de lijst");

        $customText = $this->normalizeDiscoveryValue(
            $this->getDiscoveryCustomText($raw)
        );

        if ($customText === "") return $otherOption;

        $length = mb_strlen($customText);

        if ($length < $customMin) {
            throw new FieldValidationException(
                $field,
                sprintf(
                    'De toelichting moet minimaal %d tekens bevatten.',
                    $customMin
                )
            );
        }

        if ($length > $customMax) {
            throw new FieldValidationException(
                $field,
                sprintf(
                    'De toelichting mag maximaal %d tekens bevatten.',
                    $customMax
                )
            );
        }

        if (preg_match($regex, $customText) !== 1) {
            throw new FieldValidationException(
                $field,
                'De toelichting bevat ongeldige tekens.'
            );
        }


        return $otherOption . ': ' . $customText;
    }
    
    public function schoolSector(
        string $field,
        mixed $value,
        array $rules
        ): string {
            $raw    = trim($value) ?? '';
            $sector = $this->text($field, $raw, $rules);

            if (!BookingProgramConfig::isValidSchoolSectorValue($sector))
                throw new FieldValidationException(
                $field,
                'Ongeldig onderwijs sector.'
            );

            return $sector;

    }

    public function normalizePhonenumber(string $phonenumber): string {
        $normalized = trim($phonenumber);
        $normalized = str_replace("\u{00A0}", ' ', $normalized);
        $normalized = preg_replace('/[ \t]+/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s*-\s*/u', '-', $normalized) ?? $normalized;
        $normalized = preg_replace('/^\+\s+/u', '+', $normalized) ?? $normalized;
        return $normalized;
    }

    private function normalizeDiscoveryValue(string $value): string
    {
        $normalized = trim($value);
        $normalized = str_replace("\u{00A0}", ' ', $normalized);
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    public function getDiscoveryCustomText(string $discoveryText): string {
        return  trim(
            mb_substr(
                $discoveryText, 
                mb_strlen(
                    FormRules::GEOFORT_DISCOVERY_OTHER_OPTION_VALIDATE_VALUE)
                ) ?? ''
        );
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

    private function isValidEmail(string $email): bool 
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

?>