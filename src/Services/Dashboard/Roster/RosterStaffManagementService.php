<?php

declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Roster;

use GeoFort\Services\Sql\RosterStaffManagementSqlRepository;
use GeoFort\Services\Sql\RosterStaffSqlRepository;
use PDO;
use PDOException;
use Throwable;

final readonly class RosterStaffManagementService
{
    public function __construct(private PDO $pdo, private RosterStaffSqlRepository $staff, private RosterStaffManagementSqlRepository $repository) {}

    public function show(int $staffId): array
    {
        $this->pdo->beginTransaction();
        try {
            $member = $this->member($staffId);
            $result = ['member' => RosterStaffConfigService::present($member), 'catalogRevision' => RosterStaffCatalogGuard::revision($this->pdo), 'history' => $this->repository->history($staffId)];
            $this->pdo->rollBack();
            return $result;
        } catch (Throwable $exception) { $this->pdo->rollBack(); throw $exception; }
    }

    public function catalog(): array
    {
        $this->pdo->beginTransaction();
        try {
            $result = ['items' => array_map(RosterStaffConfigService::present(...), $this->staff->catalog()),
                'catalogRevision' => RosterStaffCatalogGuard::revision($this->pdo), 'modules' => RosterStaffConfigService::moduleOptions()];
            $this->pdo->rollBack();
            return $result;
        } catch (Throwable $exception) { $this->pdo->rollBack(); throw $exception; }
    }

    public function preview(RosterStaffChange $change, int $actorId, string $secret): array
    {
        $this->pdo->beginTransaction();
        try {
            $revision = RosterStaffCatalogGuard::lock($this->pdo);
            [$before, $after, $impact] = $this->check($change, $revision);
            $context = $this->context($change, $actorId, $before, $after, $impact);
            $this->pdo->rollBack(); // Preview never persists personnel, plans or audit data.
            return ['impact' => $impact, 'impactToken' => RosterStaffImpactToken::issue($context, $secret), 'expiresInSeconds' => 300, 'catalogRevision' => $revision, 'version' => $change->expectedVersion];
        } catch (Throwable $exception) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $exception; }
    }

    public function save(RosterStaffChange $change, int $actorId, string $secret): array
    {
        return $this->persist(static fn (): RosterStaffChange => $change, $actorId, $secret);
    }

    public function deactivate(int $staffId, int $expectedVersion, int $expectedCatalogRevision, string $impactToken, int $actorId, string $secret): array
    {
        // Resolve unchanged values only after acquiring the same catalog lock as every staff mutation.
        return $this->persist(function () use ($staffId, $expectedVersion, $expectedCatalogRevision, $impactToken): RosterStaffChange {
            $member = $this->member($staffId);
            $active = array_column(RosterStaffConfigService::moduleOptions(), null, 'moduleKey');
            return RosterStaffChange::fromArray([
                'staffId' => $staffId, 'expectedVersion' => $expectedVersion, 'expectedCatalogRevision' => $expectedCatalogRevision,
                'name' => $member['name'], 'isActive' => false, 'employmentType' => $member['employmentType'],
                'canGuide' => $member['canGuide'], 'canCook' => $member['canCook'],
                'preferences' => array_values(array_filter($member['preferences'], static fn (array $p): bool => isset($active[$p['moduleKey']]))),
                'impactToken' => $impactToken,
            ]);
        }, $actorId, $secret);
    }

    private function persist(callable $resolveChange, int $actorId, string $secret): array
    {
        if ($actorId < 1) throw new RosterPlanException('UNAUTHENTICATED', 401, 'Log opnieuw in.');
        $this->pdo->beginTransaction();
        try {
            $revision = RosterStaffCatalogGuard::lock($this->pdo);
            $change = $resolveChange();
            RosterStaffCatalogGuard::expect($revision, $change->expectedCatalogRevision);
            if ($change->staffId === null) {
                $before = null; $after = $change->values; $impact = [];
                RosterStaffPreferencePolicy::validate(null, $after['preferences']);
                $id = $this->repository->insert($after); $previousVersion = 0;
            } else {
                [$before, $after, $impact] = $this->check($change, $revision);
                RosterStaffImpactToken::verify($change->impactToken, $this->context($change, $actorId, $before, $after, $impact), $secret);
                $id = $change->staffId; $previousVersion = $change->expectedVersion;
                if ($before === $after) {
                    $this->pdo->rollBack();
                    return $this->show($id);
                }
                if (!$this->repository->update($id, $previousVersion, $after)) throw $this->stale();
            }
            $this->repository->preferences($id, $before['preferences'] ?? [], $after['preferences']);
            $this->repository->audit($id, $actorId, $previousVersion, $before, $after, $impact);
            $this->pdo->commit();
            return $this->show($id);
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if ($exception instanceof PDOException && (int) ($exception->errorInfo[1] ?? 0) === 1062) {
                throw new RosterPlanException('STAFF_NAME_EXISTS', 409, 'Er bestaat al een medewerker met deze weergavenaam.');
            }
            throw $exception;
        }
    }

    private function check(RosterStaffChange $change, int $revision): array
    {
        RosterStaffCatalogGuard::expect($revision, $change->expectedCatalogRevision);
        $member = $this->member($change->staffId ?? 0);
        if ($member['version'] !== $change->expectedVersion) throw $this->stale();
        $before = array_intersect_key($member, $change->values);
        // Stable ordering is part of the signed confirmation.
        $before = array_replace($change->values, $before);
        usort($before['preferences'], static fn (array $a, array $b): int => strcmp($a['moduleKey'], $b['moduleKey']));
        $after = $change->values;
        $active = array_column(RosterStaffConfigService::moduleOptions(), null, 'moduleKey');
        $legacy = [];
        foreach ($before['preferences'] as $preference) if (!isset($active[$preference['moduleKey']])) $legacy[$preference['moduleKey']] = $preference;
        foreach ($change->removeLegacyModuleKeys as $key) {
            if (!isset($legacy[$key])) throw new RosterPlanException('INVALID_LEGACY_SKILL', 422, 'Alleen een bestaande legacyvaardigheid kan expliciet worden verwijderd.');
            unset($legacy[$key]);
        }
        foreach ($change->legacyPreferenceRanks as $preference) {
            if (!isset($legacy[$preference['moduleKey']])) throw new RosterPlanException('INVALID_LEGACY_SKILL', 422, 'Alleen de rang van een bestaande, behouden legacyvaardigheid kan worden gewijzigd.');
            $legacy[$preference['moduleKey']]['rank'] = $preference['rank'];
        }
        $after['preferences'] = [...$after['preferences'], ...array_values($legacy)];
        usort($after['preferences'], static fn (array $a, array $b): int => strcmp($a['moduleKey'], $b['moduleKey']));
        RosterStaffPreferencePolicy::validate($before['preferences'], $after['preferences']);
        $removedSkills = array_diff(array_column($before['preferences'], 'moduleKey'), array_column($after['preferences'], 'moduleKey'));
        $impact = [];
        foreach ($this->repository->conceptReferences($member['id']) as $reference) {
            $reasons = [];
            if ($before['isActive'] && !$after['isActive']) $reasons[] = 'Medewerker wordt inactief maar is geselecteerd of toegewezen.';
            if ($reference['kind'] === 'guide' && $before['canGuide'] && !$after['canGuide']) $reasons[] = 'Bestaande begeleiderstoewijzing verliest de bevoegdheid begeleiden.';
            if ($reference['kind'] === 'cook' && $before['canCook'] && !$after['canCook']) $reasons[] = 'Bestaande kokkeuze verliest de bevoegdheid koken.';
            if ($reference['kind'] === 'guide' && in_array($reference['module_key'], $removedSkills, true)) $reasons[] = 'Gebruikte modulevaardigheid vervalt: ' . $reference['module_key'];
            if ($reasons === []) continue;
            $id = (int) $reference['id'];
            $impact[$id] ??= ['planId' => $id, 'visitDate' => $reference['visit_date'], 'revision' => (int) $reference['revision'], 'reasons' => []];
            $impact[$id]['reasons'] = array_values(array_unique([...$impact[$id]['reasons'], ...$reasons]));
        }
        foreach ($impact as &$item) sort($item['reasons'], SORT_STRING);
        unset($item);
        return [$before, $after, array_values($impact)];
    }

    private function context(RosterStaffChange $change, int $actorId, array $before, array $after, array $impact): array
    {
        return ['staffId' => $change->staffId, 'actorId' => $actorId, 'version' => $change->expectedVersion,
            'catalogRevision' => $change->expectedCatalogRevision, 'before' => $before, 'after' => $after,
            'removeLegacyModuleKeys' => $change->removeLegacyModuleKeys, 'activeModules' => RosterStaffConfigService::moduleOptions(), 'impact' => $impact];
    }

    private function member(int $id): array
    {
        return $this->staff->memberById($id) ?? throw new RosterPlanException('STAFF_NOT_FOUND', 404, 'Medewerker niet gevonden.');
    }

    private function stale(): RosterPlanException
    {
        return new RosterPlanException('STALE_STAFF', 409, 'Deze medewerker is intussen gewijzigd. Laad de actuele gegevens en controleer opnieuw.');
    }
}
