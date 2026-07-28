<?php

declare(strict_types=1);

namespace GeoFort\Services\Sql;

use GeoFort\Booking\Cjp\BookingCjpDetails;
use PDO;
use PDOException;
use RuntimeException;

final readonly class BookingCjpSqlRepository
{
    public function __construct(private PDO $pdo) {}

    public function guardedUpdate(int $id, BookingCjpDetails $expected, BookingCjpDetails $new): bool
    {
        try {
            $statement = $this->pdo->prepare(<<<'SQL'
                UPDATE aanvragen
                SET cjpPasGebruik=:newUse, cjpContactpersoonNaam=:newName, cjpPasnummer=:newNumber
                WHERE id=:id AND cjpPasGebruik=:expectedUse
                  AND cjpContactpersoonNaam <=> :expectedName
                  AND cjpPasnummer <=> :expectedNumber
                SQL);
            $statement->execute([
                ':id'=>$id, ':newUse'=>$new->useCjp, ':newName'=>$new->contactName,
                ':newNumber'=>$new->cardNumber, ':expectedUse'=>$expected->useCjp,
                ':expectedName'=>$expected->contactName, ':expectedNumber'=>$expected->cardNumber,
            ]);
            return $statement->rowCount() === 1;
        } catch (PDOException $exception) {
            throw new RuntimeException('CJP-gegevens konden niet worden gewijzigd.', 0, $exception);
        }
    }
}
