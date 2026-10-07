<?php

declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Roster;

interface RosterSchoolPdfRenderer
{
    public function render(array $school): string;
}
