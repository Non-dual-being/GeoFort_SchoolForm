<?php

declare(strict_types=1);

namespace GeoFort\Dashboard\Calendar;

final readonly class CalendarOverviewDisabledDate
{
    public function __construct(public string $type, public string $source, public string $label) {}

    /** @return array{type: string, source: string, label: string} */
    public function toArray(): array
    {
        return ['type' => $this->type, 'source' => $this->source, 'label' => $this->label];
    }
}
