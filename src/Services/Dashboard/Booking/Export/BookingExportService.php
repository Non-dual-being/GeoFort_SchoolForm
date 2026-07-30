<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking\Export;

use GeoFort\Services\Sql\BookingExportSqlRepository;
use Generator;
use PDOStatement;

final readonly class BookingExportService
{
    public function __construct(
        private BookingExportSqlRepository $repository,
        private BookingExportRowFactory $rowFactory,
    ) {}

    public function count(BookingExportCriteria $criteria): int
    {
        return $this->repository->count($criteria);
    }

    public function prepare(BookingExportCriteria $criteria): PDOStatement
    {
        return $this->repository->openExportCursor($criteria);
    }

    /** @return Generator<int, BookingExportRow> */
    public function rows(PDOStatement $statement): Generator
    {
        while (($row = $statement->fetch()) !== false) {
            if (is_array($row)) {
                yield $this->rowFactory->create($row);
            }
        }
    }
}
