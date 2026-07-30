<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking\Export;

use RuntimeException;

final class BookingCsvWriter
{
    /** @param resource $stream @param iterable<BookingExportRow> $rows */
    public function write($stream, iterable $rows): void
    {
        if (!is_resource($stream)) {
            throw new RuntimeException('CSV-uitvoer kon niet worden geopend.');
        }
        if (fwrite($stream, "\xEF\xBB\xBF") === false) {
            throw new RuntimeException('CSV-uitvoer kon niet worden gestart.');
        }
        $this->writeLine($stream, BookingExportRowFactory::HEADERS);
        foreach ($rows as $row) {
            $this->writeLine($stream, $row->values);
        }
    }

    /** @param resource $stream @param list<string> $values */
    private function writeLine($stream, array $values): void
    {
        if (fputcsv($stream, $values, ';', '"', '', "\r\n") === false) {
            throw new RuntimeException('CSV-regel kon niet worden geschreven.');
        }
    }
}
