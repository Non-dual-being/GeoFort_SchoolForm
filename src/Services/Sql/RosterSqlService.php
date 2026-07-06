<?php

declare(strict_types=1);

namespace GeoFort\Services\Sql;

use PDO;
use PDOException;
use RuntimeException;

final class RosterSqlService
{
    public function __construct(private readonly PDO $pdo)
    {}

    /**
     * @return array{
     *   id: int|string,
     *   schooltype: string,
     *   keuzemodule: string,
     *   leerlingen_min: int|string,
     *   leerlingen_max: int|string,
     *   programmaduur: string,
     *   afbeelding: string|null,
     *   pdf: string|null
     * }|null
     */
    public function findRoster(
        string $schoolType,
        string $moduleKey,
        string $programDuration,
        int $studentCount,
    ): ?array {
        try {
            $sql = "
                SELECT
                    id,
                    schooltype,
                    keuzemodule,
                    leerlingen_min,
                    leerlingen_max,
                    programmaduur,
                    afbeelding,
                    pdf
                FROM roosters
                WHERE schooltype = :schooltype
                  AND keuzemodule = :keuzemodule
                  AND programmaduur = :programmaduur
                  AND leerlingen_min <= :studentCountMin
                  AND leerlingen_max >= :studentCountMax
                LIMIT 1
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':schooltype' => $schoolType,
                ':keuzemodule' => $moduleKey,
                ':programmaduur' => $programDuration,
                ':studentCountMin' => $studentCount,
                ':studentCountMax' => $studentCount,
            ]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return is_array($row) ? $row : null;
        } catch (PDOException $e) {
            error_log('[SQL ERROR][RosterSqlService::findRoster]: ' . $e->getMessage());

            throw new RuntimeException(
                'Rooster kon niet worden opgehaald',
                0,
                $e,
            );
        }
    }
}
