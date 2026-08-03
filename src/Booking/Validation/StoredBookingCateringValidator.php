<?php

declare(strict_types=1);

namespace GeoFort\Booking\Validation;

use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Booking\Stored\StoredBooking;
use GeoFort\Services\Booking\Data\FoodAndDrinkSelectionData;

final readonly class StoredBookingCateringValidator
{
    /** @return list<StoredBookingIssue> */
    public function validate(StoredBooking $booking): array
    {
        $food = $booking->foodAndDrinkSelection;
        $issues = [];
        $snacks = [
            'remiseBreak' => ['remise_break', $food->remiseBreak],
            'kazerneBreak' => ['kazerne_break', $food->kazerneBreak],
            'fortgrachtBreak' => ['fortgracht_break', $food->fortgrachtBreak],
            'waterIce' => ['waterijsje', $food->waterijsje],
            'lemonade' => ['glas_limonade', $food->glasLimonade],
        ];

        foreach ($snacks as $field => [$key, $quantity]) {
            $config = BookingProgramConfig::FOOD_AND_DRINK_OPTIONS['snacks'][$key] ?? null;
            $price = BookingProgramConfig::priceCents($key);
            if (!$this->validConfig($config, $price)) {
                $issues[] = $this->issue('INVALID_CATERING_CONFIGURATION', $field, ['optionKey' => $key]);
                continue;
            }
            $metadata = $this->metadata($key, $quantity, $config);
            if ($quantity < 0) {
                $issues[] = $this->issue('INVALID_CATERING_QUANTITY', $field, $metadata);
            } elseif ($quantity > $config['max']) {
                $issues[] = $this->issue('CATERING_OPTION_LIMIT_EXCEEDED', $field, $metadata);
            }
        }

        $lunchConfig = BookingProgramConfig::FOOD_AND_DRINK_OPTIONS['lunch']['remise_lunch'] ?? null;
        $lunchPrice = BookingProgramConfig::priceCents('remise_lunch');
        $picnicConfig = BookingProgramConfig::FOOD_AND_DRINK_OPTIONS['lunch']['eigen_picknick'] ?? null;
        $picnicPrice = BookingProgramConfig::priceCents('eigen_picknick');
        if (!$this->validConfig($lunchConfig, $lunchPrice) || !$this->validConfig($picnicConfig, $picnicPrice)) {
            $issues[] = $this->issue('INVALID_CATERING_CONFIGURATION', 'remiseLunch', ['optionKey' => 'remise_lunch']);
            return $issues;
        }
        $lunchMetadata = [
            ...$this->metadata('remise_lunch', $food->remiseLunch, $lunchConfig),
            'lunchChoice' => $food->lunchChoice,
            'remiseLunch' => $food->remiseLunch,
            'ownPicnic' => $food->eigenPicknick,
        ];
        if ($food->remiseLunch < 0) {
            $issues[] = $this->issue('INVALID_CATERING_QUANTITY', 'remiseLunch', $lunchMetadata);
        } elseif ($food->remiseLunch > 0 && $food->eigenPicknick) {
            $issues[] = $this->issue('CONFLICTING_LUNCH_SELECTION', 'lunchChoice', $lunchMetadata);
        } elseif ($food->lunchChoice === FoodAndDrinkSelectionData::LUNCH_NONE) {
            $issues[] = $this->issue('MISSING_LUNCH_SELECTION', 'lunchChoice', $lunchMetadata);
        } elseif ($food->lunchChoice === FoodAndDrinkSelectionData::LUNCH_REMISE
            && ($food->remiseLunch < $lunchConfig['min'] || $food->remiseLunch > $lunchConfig['max'])) {
            $issues[] = $this->issue('INVALID_REMISE_LUNCH_QUANTITY', 'remiseLunch', $lunchMetadata);
            if ($food->remiseLunch > $lunchConfig['max']) {
                $issues[] = $this->issue('CATERING_OPTION_LIMIT_EXCEEDED', 'remiseLunch', $lunchMetadata);
            }
        } elseif ($food->lunchChoice === FoodAndDrinkSelectionData::LUNCH_OWN_PICNIC
            && ($food->remiseLunch !== 0 || !$food->eigenPicknick)) {
            $issues[] = $this->issue('CONFLICTING_LUNCH_SELECTION', 'lunchChoice', $lunchMetadata);
        }
        return $issues;
    }

    /** @param mixed $config */
    private function validConfig(mixed $config, mixed $price): bool
    {
        return is_array($config)
            && isset($config['label'], $config['min'], $config['max'])
            && is_string($config['label'])
            && is_int($config['min'])
            && is_int($config['max'])
            && $config['min'] >= 0
            && $config['max'] >= $config['min']
            && is_int($price)
            && $price >= 0;
    }

    /** @param array<string,mixed> $config @return array<string,bool|int|string|null> */
    private function metadata(string $key, int $quantity, array $config): array
    {
        return ['optionKey' => $key, 'optionLabel' => $config['label'], 'quantity' => $quantity, 'minimum' => $config['min'], 'maximum' => $config['max']];
    }

    /** @param array<string,bool|int|string|null> $metadata */
    private function issue(string $code, string $field, array $metadata): StoredBookingIssue
    {
        return new StoredBookingIssue($code, StoredBookingIssueCategory::Policy, $field, metadata: $metadata);
    }
}
