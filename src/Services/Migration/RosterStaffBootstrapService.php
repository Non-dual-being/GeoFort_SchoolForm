<?php

declare(strict_types=1);

namespace GeoFort\Services\Migration;

use GeoFort\Services\Dashboard\Roster\RosterStaffCatalogGuard;
use PDO;
use RuntimeException;
use Throwable;

/** Explicit deployment operation. Never called from HTTP/bootstrap.php. */
final readonly class RosterStaffBootstrapService
{
    public function __construct(private PDO $pdo, private string $seedPath) {}

    public function run(bool $freshInstallation = false): array
    {
        $this->pdo->beginTransaction();
        try {
            RosterStaffCatalogGuard::lock($this->pdo);
            $state = $this->pdo->query('SELECT bootstrap_status, bootstrap_seed_hash FROM roster_staff_catalog_state WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
            if ($state['bootstrap_status'] !== 'pending') {
                $this->pdo->rollBack();
                return ['status' => $state['bootstrap_status'], 'changed' => false, 'membersInserted' => 0, 'skillsInserted' => 0];
            }
            $members = (int) $this->pdo->query('SELECT COUNT(*) FROM roster_staff_members')->fetchColumn();
            $plans = (int) $this->pdo->query('SELECT COUNT(*) FROM roster_plans')->fetchColumn();
            $hash = null; $inserted = 0; $skills = 0;
            // Existing/deviating/empty operational catalogs remain authoritative.
            $status = 'adopted_existing';
            if ($freshInstallation && $members === 0 && $plans === 0) {
                $bytes = file_get_contents($this->seedPath);
                if ($bytes === false) throw new RuntimeException('Approved staff seed is missing.');
                $seed = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
                $rows = $seed['members'] ?? [];
                if (($seed['formatVersion'] ?? null) !== 1 || count($rows) !== 20 || array_column($rows, 'id') !== range(1, 20)
                    || array_sum(array_map(static fn (array $row): int => count($row['preferences']), $rows)) !== 86) throw new RuntimeException('Unexpected initial staff catalog.');
                $member = $this->pdo->prepare('INSERT INTO roster_staff_members (id, display_name, is_active, employment_type, can_guide, can_cook, version) VALUES (?, ?, ?, ?, ?, ?, 1)');
                $preference = $this->pdo->prepare('INSERT INTO roster_staff_module_preferences (staff_id, module_key, preference_rank) VALUES (?, ?, ?)');
                foreach ($rows as $row) {
                    $member->execute([$row['id'], $row['display_name'], $row['is_active'], $row['employment_type'], $row['can_guide'], $row['can_cook']]);
                    $inserted++;
                    foreach ($row['preferences'] as $skill) { $preference->execute([$row['id'], $skill['module_key'], $skill['preference_rank']]); $skills++; }
                }
                $status = 'seeded'; $hash = hash('sha256', $bytes);
            }
            $statement = $this->pdo->prepare('UPDATE roster_staff_catalog_state SET bootstrap_status = ?, bootstrap_seed_hash = ?, bootstrap_completed_at = UTC_TIMESTAMP(6) WHERE id = 1');
            $statement->execute([$status, $hash]);
            $this->pdo->commit();
            return ['status' => $status, 'changed' => true, 'membersInserted' => $inserted, 'skillsInserted' => $skills];
        } catch (Throwable $exception) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $exception; }
    }
}
