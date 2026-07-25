<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\Catering;

use GeoFort\Services\Booking\Data\FoodAndDrinkSelectionData;

final readonly class BookingCateringValues
{
    public function __construct(
        public int $remiseBreak,
        public int $kazerneBreak,
        public int $fortgrachtBreak,
        public int $waterIce,
        public int $lemonade,
        public int $remiseLunch,
        public bool $ownPicnic,
    ) {}

    public static function fromSelection(FoodAndDrinkSelectionData $selection): self
    {
        return new self($selection->remiseBreak, $selection->kazerneBreak, $selection->fortgrachtBreak, $selection->waterijsje, $selection->glasLimonade, $selection->remiseLunch, $selection->eigenPicknick);
    }

    /** @return array<string,int|bool> */
    public function toArray(): array
    {
        return ['remiseBreak'=>$this->remiseBreak,'kazerneBreak'=>$this->kazerneBreak,'fortgrachtBreak'=>$this->fortgrachtBreak,'waterIce'=>$this->waterIce,'lemonade'=>$this->lemonade,'remiseLunch'=>$this->remiseLunch,'ownPicnic'=>$this->ownPicnic];
    }
}
