<?php
declare(strict_types=1);
namespace GeoFort\Services\Sql;

use GeoFort\Booking\Rules\BookingRuleOverrideScope;
use GeoFort\Booking\Rules\UsedBookingRuleOverride;
use JsonException;
use PDO;
use PDOException;
use RuntimeException;

final readonly class BookingRuleOverrideSqlRepository
{
    public function __construct(private PDO $pdo) {}

    public function insert(int $bookingId, int $statusHistoryId, UsedBookingRuleOverride $override, int $adminId): void
    {
        try {
            $metadata = json_encode($override->metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $statement = $this->pdo->prepare(<<<'SQL'
                INSERT INTO booking_rule_overrides (
                    booking_id, status_history_id, rule_code, scope, reason,
                    context_fingerprint, metadata_json, approved_by_admin_id, created_at
                ) VALUES (:bookingId, :historyId, :ruleCode, :scope, :reason, :fingerprint, :metadata, :adminId, NOW())
                SQL);
            $statement->execute([':bookingId'=>$bookingId,':historyId'=>$statusHistoryId,':ruleCode'=>$override->ruleCode,':scope'=>BookingRuleOverrideScope::SingleOperation->value,':reason'=>$override->reason,':fingerprint'=>$override->contextFingerprint,':metadata'=>$metadata,':adminId'=>$adminId]);
        } catch (PDOException|JsonException $exception) {
            throw new RuntimeException('Boekingsregeloverride kon niet worden vastgelegd.', 0, $exception);
        }
    }

    public function insertForChange(int $bookingId, int $changeHistoryId, UsedBookingRuleOverride $override, int $adminId): void
    {
        $this->insertLinked($bookingId, null, $changeHistoryId, $override, $adminId);
    }

    private function insertLinked(int $bookingId, ?int $statusHistoryId, ?int $changeHistoryId, UsedBookingRuleOverride $override, int $adminId): void
    {
        if (($statusHistoryId === null) === ($changeHistoryId === null)) throw new RuntimeException('Override vereist exact één operatiehistorie.');
        try {
            $metadata = json_encode($override->metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $statement = $this->pdo->prepare(<<<'SQL'
                INSERT INTO booking_rule_overrides (
                    booking_id, status_history_id, booking_change_history_id, rule_code, scope, reason,
                    context_fingerprint, metadata_json, approved_by_admin_id, created_at
                ) VALUES (:bookingId, :statusHistoryId, :changeHistoryId, :ruleCode, :scope, :reason, :fingerprint, :metadata, :adminId, NOW())
                SQL);
            $statement->execute([
                ':bookingId' => $bookingId,
                ':statusHistoryId' => $statusHistoryId,
                ':changeHistoryId' => $changeHistoryId,
                ':ruleCode' => $override->ruleCode,
                ':scope' => BookingRuleOverrideScope::SingleOperation->value,
                ':reason' => $override->reason,
                ':fingerprint' => $override->contextFingerprint,
                ':metadata' => $metadata,
                ':adminId' => $adminId,
            ]);
        } catch (PDOException|JsonException $exception) {
            throw new RuntimeException('Boekingsregeloverride kon niet worden vastgelegd.', 0, $exception);
        }
    }
}
