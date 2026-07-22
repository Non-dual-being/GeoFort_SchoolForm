<?php
declare(strict_types=1);
namespace GeoFort\Services\Sql;

use PDO;
use PDOException;
use RuntimeException;

final readonly class BookingAttendanceSqlRepository
{
    public function __construct(private PDO $pdo) {}

    public function guardedUpdate(int $id, int $expectedStudents, int $expectedSupervisors, int $students, int $supervisors): bool
    {
        try {
            $statement = $this->pdo->prepare('UPDATE aanvragen SET aantal_leerlingen = :students, aantal_begeleiders = :supervisors WHERE id = :id AND aantal_leerlingen = :expectedStudents AND aantal_begeleiders = :expectedSupervisors');
            $statement->execute([':students'=>$students, ':supervisors'=>$supervisors, ':id'=>$id, ':expectedStudents'=>$expectedStudents, ':expectedSupervisors'=>$expectedSupervisors]);
            return $statement->rowCount() === 1;
        } catch (PDOException $exception) {
            throw new RuntimeException('Aantallen konden niet worden gewijzigd.', 0, $exception);
        }
    }
}
