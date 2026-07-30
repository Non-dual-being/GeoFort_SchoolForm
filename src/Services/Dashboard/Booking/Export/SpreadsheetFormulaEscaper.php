<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking\Export;

final class SpreadsheetFormulaEscaper
{
    public function escape(string $value): string
    {
        return preg_match('/^[ ]*(?:[=+\-@]|\t|\r)/', $value) === 1
            ? "'" . $value
            : $value;
    }
}
