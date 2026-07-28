<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\Cjp;

use GeoFort\Booking\Cjp\BookingCjpDetails;
use GeoFort\Booking\Cjp\BookingCjpIssue;
use GeoFort\Validation\FieldValidationException;
use GeoFort\Validation\FormRules;
use GeoFort\Validation\Validator;

final readonly class BookingCjpValidator
{
    public function __construct(private Validator $validator = new Validator()) {}

    /** @return array{details:?BookingCjpDetails,issues:list<BookingCjpIssue>} */
    public function validate(BookingCjpDetails $raw): array
    {
        try {
            $useCjp = $this->validator->text('cjpPasGebruik', $raw->useCjp, FormRules::RULES);
        } catch (FieldValidationException $exception) {
            return ['details'=>null, 'issues'=>[$this->issue('INVALID_CJP_SELECTION', 'useCjp', $exception->getMessage())]];
        }

        if ($useCjp === 'nee') {
            return ['details'=>new BookingCjpDetails('nee', null, null), 'issues'=>[]];
        }

        $issues = [];
        $name = $this->normalizeName($raw->contactName ?? '');
        $cardNumber = $this->normalizeCardNumber($raw->cardNumber ?? '');
        foreach ([
            'contactName'=>fn()=> $this->validator->text('cjpContactpersoonNaam', $name, FormRules::RULES),
            'cardNumber'=>fn()=> $this->validator->text('cjpPasnummer', $cardNumber, FormRules::RULES),
        ] as $field=>$check) {
            try {
                if ($field === 'contactName') $name = $check();
                else $cardNumber = $check();
            } catch (FieldValidationException $exception) {
                $issues[] = $this->issue(
                    $field === 'contactName' ? 'INVALID_CJP_CONTACT_NAME' : 'INVALID_CJP_CARD_NUMBER',
                    $field,
                    $exception->getMessage(),
                );
            }
        }
        return $issues === []
            ? ['details'=>new BookingCjpDetails('ja', $name, $cardNumber), 'issues'=>[]]
            : ['details'=>null, 'issues'=>$issues];
    }

    private function normalizeName(string $value): string
    {
        $value = str_replace("\u{00A0}", ' ', trim($value));
        return preg_replace('/\s+/u', ' ', $value) ?? $value;
    }

    private function normalizeCardNumber(string $value): string
    {
        return preg_replace('/\s+/u', '', trim($value)) ?? trim($value);
    }

    private function issue(string $code, string $field, string $description): BookingCjpIssue
    {
        return new BookingCjpIssue($code, $field, 'Controleer dit CJP-veld', $description);
    }
}
