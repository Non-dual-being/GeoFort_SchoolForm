<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\Data;

final readonly class FoodAndDrinkSelectionData
{
    public const LUNCH_NONE = 'none';
    public const LUNCH_REMISE = 'remise_lunch';
    public const LUNCH_OWN_PICNIC = 'eigen_picknick';
    public const LUNCH_CONFLICT = 'conflict';

    public function __construct(
        public int $remiseBreak,
        public int $kazerneBreak,
        public int $fortgrachtBreak,
        public int $waterijsje,
        public int $glasLimonade,
        public string $lunchChoice,
        public int $remiseLunch,
        public bool $eigenPicknick,
    ) {}

    public static function fromStoredValues(
        int $remiseBreak,
        int $kazerneBreak,
        int $fortgrachtBreak,
        int $waterijsje,
        int $glasLimonade,
        int $remiseLunch,
        bool $eigenPicknick,
    ): self {
        $lunchChoice = match (true) {
            $remiseLunch > 0 && $eigenPicknick => self::LUNCH_CONFLICT,
            $remiseLunch > 0 => self::LUNCH_REMISE,
            $eigenPicknick => self::LUNCH_OWN_PICNIC,
            default => self::LUNCH_NONE,
        };

        return new self(
            $remiseBreak,
            $kazerneBreak,
            $fortgrachtBreak,
            $waterijsje,
            $glasLimonade,
            $lunchChoice,
            $remiseLunch,
            $eigenPicknick,
        );
    }

    public function hasFoodOrder(): bool
    {
        return $this->remiseBreak > 0
            || $this->kazerneBreak > 0
            || $this->fortgrachtBreak > 0
            || $this->waterijsje > 0
            || $this->glasLimonade > 0
            || $this->remiseLunch > 0;
    }

    public function orderedQuantities(): array
    {
        return array_filter(
            [
                'remise_break' => $this->remiseBreak,
                'kazerne_break' => $this->kazerneBreak,
                'fortgracht_break' => $this->fortgrachtBreak,
                'waterijsje' => $this->waterijsje,
                'glas_limonade' => $this->glasLimonade,
                'remise_lunch' => $this->remiseLunch,
            ],
            static fn (int $quantity): bool => $quantity > 0,
        );
    }

    public function toDatabaseParams(): array
    {
        return [
            ':remiseBreak' => $this->remiseBreak,
            ':kazerneBreak' => $this->kazerneBreak,
            ':fortgrachtBreak' => $this->fortgrachtBreak,
            ':waterijsje' => $this->waterijsje,
            ':glasLimonade' => $this->glasLimonade,
            ':remiseLunch' => $this->remiseLunch,
            ':eigenPicknick' => $this->eigenPicknick ? 1 : 0,
        ];
    }
}
