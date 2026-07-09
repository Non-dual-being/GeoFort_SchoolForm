<?php

declare(strict_types=1);

namespace GeoFort\Validation;

use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Services\Booking\Data\FoodAndDrinkSelectionData;

final class FoodAndDrinkSelectionValidator
{
    private const SNACK_FIELDS = [
        'remiseBreak' => 'remise_break',
        'kazerneBreak' => 'kazerne_break',
        'fortgrachtBreak' => 'fortgracht_break',
        'waterijsje' => 'waterijsje',
        'glasLimonade' => 'glas_limonade',
    ];

    public function validate(array $postData): FoodAndDrinkSelectionData
    {
        $snacks = [];

        foreach (self::SNACK_FIELDS as $field => $optionKey) {
            $snacks[$field] = $this->validateSnackCount(
                field: $field,
                rawValue: $postData[$field] ?? '',
                optionKey: $optionKey,
            );
        }

        $lunchChoice = $this->validateLunchChoice($postData['lunchChoice'] ?? '');

        if ($lunchChoice === 'remise_lunch') {
            $remiseLunch = $this->validateRemiseLunch($postData['remiseLunch'] ?? '');
            $eigenPicknick = false;
        } else {
            $remiseLunch = 0;
            $eigenPicknick = true;
        }

        return new FoodAndDrinkSelectionData(
            remiseBreak: $snacks['remiseBreak'],
            kazerneBreak: $snacks['kazerneBreak'],
            fortgrachtBreak: $snacks['fortgrachtBreak'],
            waterijsje: $snacks['waterijsje'],
            glasLimonade: $snacks['glasLimonade'],
            lunchChoice: $lunchChoice,
            remiseLunch: $remiseLunch,
            eigenPicknick: $eigenPicknick,
        );
    }

    private function validateSnackCount(
        string $field,
        mixed $rawValue,
        string $optionKey,
    ): int {
        $value = $this->normalizeRawValue($rawValue);

        if ($value === '') {
            return 0;
        }

        $label = BookingProgramConfig::FOOD_AND_DRINK_OPTIONS['snacks'][$optionKey]['label'];
        $max = BookingProgramConfig::FOOD_AND_DRINK_OPTIONS['snacks'][$optionKey]['max'];

        if (!preg_match('/^[0-9]+$/', $value)) {
            throw new FieldValidationException(
                $field,
                "Vul een geldig aantal in voor {$label}.",
            );
        }

        $count = (int) $value;

        if ($count < 0) {
            throw new FieldValidationException(
                $field,
                "Vul een geldig aantal in voor {$label}.",
            );
        }

        if ($count > $max) {
            throw new FieldValidationException(
                $field,
                "Voor {$label} kunt u maximaal {$max} stuks opgeven.",
            );
        }

        return $count;
    }

    private function validateLunchChoice(mixed $rawValue): string
    {
        $value = $this->normalizeRawValue($rawValue);

        if (!in_array($value, ['remise_lunch', 'eigen_picknick'], true)) {
            throw new FieldValidationException(
                'lunchChoice',
                'Kies of u een remiselunch wilt bestellen of eigen lunch meeneemt.',
            );
        }

        return $value;
    }

    private function validateRemiseLunch(mixed $rawValue): int
    {
        $value = $this->normalizeRawValue($rawValue);

        if ($value === '' || !preg_match('/^[0-9]+$/', $value)) {
            throw new FieldValidationException(
                'remiseLunch',
                'Vul een geldig aantal in voor Remiselunch.',
            );
        }

        $count = (int) $value;
        $option = BookingProgramConfig::FOOD_AND_DRINK_OPTIONS['lunch']['remise_lunch'];
        $min = $option['min'];
        $max = $option['max'];

        if ($count < $min) {
            throw new FieldValidationException(
                'remiseLunch',
                'Voor de remiselunch geldt een minimum van 50 stuks.',
            );
        }

        if ($count > $max) {
            throw new FieldValidationException(
                'remiseLunch',
                'Voor de remiselunch kunt u maximaal 200 stuks opgeven.',
            );
        }

        return $count;
    }

    private function normalizeRawValue(mixed $rawValue): string
    {
        if (is_int($rawValue)) {
            return (string) $rawValue;
        }

        if (is_string($rawValue)) {
            return trim($rawValue);
        }

        return '';
    }
}
