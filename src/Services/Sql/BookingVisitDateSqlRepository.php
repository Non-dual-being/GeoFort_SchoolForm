<?php
declare(strict_types=1);
namespace GeoFort\Services\Sql;

use PDO;
use PDOException;
use RuntimeException;

final readonly class BookingVisitDateSqlRepository
{
    public function __construct(private PDO $pdo) {}

    public function guardedUpdate(int $id, string $expected, string $proposed): bool
    {
        try {
            $statement=$this->pdo->prepare('UPDATE aanvragen SET bezoekdatum = :proposed WHERE id = :id AND bezoekdatum = :expected');
            $statement->bindValue(':proposed',$proposed);
            $statement->bindValue(':id',$id,PDO::PARAM_INT);
            $statement->bindValue(':expected',$expected);
            $statement->execute();
            return $statement->rowCount()===1;
        } catch (PDOException $exception) {
            throw new RuntimeException('Bezoekdatum kon niet worden gewijzigd.',0,$exception);
        }
    }
}
