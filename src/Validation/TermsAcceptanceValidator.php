<?php

declare(strict_types=1);

namespace GeoFort\Validation;

final class TermsAcceptanceValidator
{
    public function validate(mixed $rawValue): bool
    {
        if ($rawValue === true || $rawValue === 1 || $rawValue === '1') {
            return true;
        }

        if (is_string($rawValue) && strtolower(trim($rawValue)) === 'true') {
            return true;
        }

        throw new FieldValidationException(
            'voorwaardenAkkoord',
            'Ga akkoord met de voorwaarden om de aanvraag te versturen.',
        );
    }
}
