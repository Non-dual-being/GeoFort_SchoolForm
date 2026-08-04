<?php
declare(strict_types=1);

namespace GeoFort\Services\Sql;

use PDO;
use PDOException;
use RuntimeException;

final readonly class BookingPriceBackfillSqlRepository
{
    public function __construct(private PDO $pdo) {}

    /** @return list<array{id: int, source_system: ?string, source_record_id: ?int, calculation_state: ?string}> */
    public function inventory(): array
    {
        try {
            $rows=$this->pdo->query(<<<'SQL'
                SELECT a.id, a.source_system, a.source_record_id, latest.calculation_state
                FROM aanvragen a
                LEFT JOIN booking_price_snapshots latest
                  ON latest.booking_id=a.id
                 AND latest.sequence_number=(SELECT MAX(s.sequence_number) FROM booking_price_snapshots s WHERE s.booking_id=a.id)
                ORDER BY a.id ASC
                SQL)->fetchAll(PDO::FETCH_ASSOC);
            return array_map(static fn(array $row):array=>['id'=>(int)$row['id'],'source_system'=>$row['source_system']===null?null:(string)$row['source_system'],'source_record_id'=>$row['source_record_id']===null?null:(int)$row['source_record_id'],'calculation_state'=>$row['calculation_state']===null?null:(string)$row['calculation_state']],$rows);
        } catch(PDOException $exception) {
            throw new RuntimeException('Backfill-inventarisatie kon niet worden uitgevoerd.',0,$exception);
        }
    }
}
