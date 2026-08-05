<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking\Revenue;

use GeoFort\Services\Dashboard\Booking\Export\SpreadsheetFormulaEscaper;
use RuntimeException;

final readonly class BookingRevenueCsvWriter
{
    public function __construct(private SpreadsheetFormulaEscaper $escaper) {}

    /** @param resource $stream @param list<array<string, mixed>> $rows */
    public function write($stream, array $rows): void
    {
        if (!is_resource($stream) || fwrite($stream, "\xEF\xBB\xBF") === false) throw new RuntimeException('CSV-uitvoer kon niet worden gestart.');
        $this->line($stream, ['Boeking-ID','Schoolnaam','Bezoekdatum','Status','Sector','Programma','Leerlingen','Bedrag excl. btw','Btw','Bedrag incl. btw','Prijsstatus']);
        foreach ($rows as $row) {
            $amounts = $row['amounts'];
            $this->line($stream, [(string)$row['id'],(string)$row['schoolName'],(string)$row['visitDate'],(string)$row['status'],(string)$row['sectorLabel'],(string)$row['program'],(string)$row['studentCount'],
                is_array($amounts)?$this->money((int)$amounts['totalExclVatCents']):'', is_array($amounts)?$this->money((int)$amounts['vatCents']):'', is_array($amounts)?$this->money((int)$amounts['totalInclVatCents']):'', is_array($amounts)?'Compleet':'Prijs ontbreekt']);
        }
    }

    /** @param resource $stream @param list<string> $values */
    private function line($stream, array $values): void { if (fputcsv($stream, array_map(fn(string $v):string=>$this->escaper->escape($v), $values), ';', '"', '', "\r\n") === false) throw new RuntimeException('CSV-regel kon niet worden geschreven.'); }
    private function money(int $cents): string { return number_format($cents / 100, 2, ',', ''); }
}
