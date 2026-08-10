<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking\Analytics;

use DateTimeImmutable;
use DateTimeZone;
use GeoFort\Dashboard\CapacityTarget\CapacityTargetPolicy;
use GeoFort\Services\Sql\CapacityTargetSqlRepository;
use PDO;
use PDOException;
use Throwable;

final readonly class CapacityTargetManagementService
{
    private const TIMEZONE = 'Europe/Amsterdam';

    public function __construct(private PDO $pdo, private CapacityTargetSqlRepository $repository) {}

    /** @return array{ok:bool,code:string,target?:array<string,mixed>,issues?:array<string,string>} */
    public function save(
        string $effectiveDate,
        mixed $students,
        mixed $bookings,
        ?string $expectedUpdatedAt,
        int $adminId,
        string $role,
    ): array {
        if (!CapacityTargetPolicy::canManage($role)) return ['ok' => false, 'code' => 'FORBIDDEN'];
        $today = (new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE)))->format('Y-m-d');
        $issues = CapacityTargetPolicy::validate($effectiveDate, $students, $bookings, $today);
        if ($issues !== []) return ['ok' => false, 'code' => 'INVALID_TARGET', 'issues' => $issues];

        try {
            $this->pdo->beginTransaction();
            $existing = $this->repository->lockByEffectiveDate($effectiveDate);
            if (($existing === null && $expectedUpdatedAt !== null)
                || ($existing !== null && ($expectedUpdatedAt === null || !hash_equals($existing->updatedAt, $expectedUpdatedAt)))) {
                $this->pdo->rollBack();
                return ['ok' => false, 'code' => 'TARGET_CONFLICT'];
            }
            $target = $existing === null
                ? $this->repository->insert($effectiveDate, $students, (float) $bookings, $adminId)
                : $this->repository->update($existing, $students, (float) $bookings, $adminId);
            $this->pdo->commit();
            return ['ok' => true, 'code' => 'SUCCESS', 'target' => $target->toArray()];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if ($exception instanceof PDOException && (string) $exception->getCode() === '23000') {
                return ['ok' => false, 'code' => 'TARGET_CONFLICT'];
            }
            $sqlState = $exception instanceof PDOException
                ? (string) ($exception->errorInfo[0] ?? $exception->getCode())
                : 'n/a';
            error_log(sprintf(
                '[CapacityTargetManagementService] action=save_capacity_target result=database_error effective_date=%s admin_id=%d exception=%s sqlstate=%s message=%s',
                $effectiveDate,
                $adminId,
                $exception::class,
                $sqlState !== '' ? $sqlState : 'n/a',
                'Het organisatietarget kon niet in de database worden opgeslagen.',
            ));
            return ['ok' => false, 'code' => 'DATABASE_ERROR'];
        }
    }
}
