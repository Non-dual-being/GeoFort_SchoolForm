<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once __DIR__ . '/DisposableBookingMariaDb.php';

use GeoFort\Booking\Stored\StoredBookingAssembler;
use GeoFort\Services\Booking\Roster\RosterGroupCountResolver;
use GeoFort\Services\Dashboard\Roster\{RosterGenerationTemplateProvider, RosterPlanningConfig, RosterPlanService, RosterSessionService, RosterValidationService, RosterLifecycleService};
use GeoFort\Services\Sql\{RosterPlanSqlRepository, RosterSessionSqlRepository, RosterStaffSqlRepository, StoredBookingSqlRepository, RosterValidationSqlRepository, RosterSnapshotSqlRepository, BookingDaySettingsSqlRepository};

final class RosterLifecycleFixtures
{
    public static function schema(PDO $pdo, bool $staffManagement = true): void
    {
        foreach (['2026-09-21_create_roster_planning_foundation', '2026-09-21_create_roster_session_planning',
            '2026-09-22_create_roster_staff_catalog', '2026-09-22_create_roster_staff_assignments', '2026-09-22_extend_roster_staff_roles',
            '2026-09-25_create_roster_session_group_staff', '2026-09-25_create_roster_plan_staff_preferences',
            '2026-09-26_create_roster_manual_plan_context', '2026-09-26_add_roster_manual_program_modules', '2026-09-26_add_roster_manual_rounds',
            '2026-10-01_add_roster_group_supervision', '2026-10-01_create_roster_final_snapshots'] as $file) {
            $pdo->exec(file_get_contents(dirname(__DIR__, 3) . '/database/sql/' . $file . '.sql'));
        }
        require_once __DIR__ . '/RosterStaffManagementFixtures.php';
        if ($staffManagement) RosterStaffManagementFixtures::schemaAndBootstrap($pdo);
    }

    public static function services(PDO $pdo): array
    {
        $plans = new RosterPlanSqlRepository($pdo); $sessions = new RosterSessionSqlRepository($pdo); $staff = new RosterStaffSqlRepository($pdo);
        $bookings = new StoredBookingSqlRepository($pdo, new StoredBookingAssembler());
        $config = new RosterPlanningConfig(); $templates = new RosterGenerationTemplateProvider();
        $raw = new RosterValidationSqlRepository($pdo); $snapshots = new RosterSnapshotSqlRepository($pdo);
        $service = new RosterPlanService($pdo, $bookings, $plans, new RosterGroupCountResolver(), $sessions, $config, $templates, $staff, null, $snapshots);
        $sessionService = new RosterSessionService($pdo, $bookings, $plans, $sessions, $config, $staff);
        $validator = new RosterValidationService($raw, $snapshots, $config, $templates);
        $lifecycle = new RosterLifecycleService($pdo, $plans, new BookingDaySettingsSqlRepository($pdo), $raw, $snapshots, $validator, $service, $config);
        return compact('plans', 'sessions', 'staff', 'bookings', 'config', 'templates', 'raw', 'snapshots', 'service', 'sessionService', 'validator', 'lifecycle');
    }

    public static function validPlan(PDO $pdo, array $services, int $students = 28, int $groups = 2, int $moduleCount = 2, string $date = '2028-10-10', ?int $bookingId = null): array
    {
        $plan = $services['service']->createManual('V1 testschoool', 'Testplaats', $date, 'primairOnderwijs', 'dag', $students, $groups, 1, $bookingId)['plan'];
        $id = $plan['id'];
        $modules = array_slice(['Earth-Watch', 'Stop-de-Klimaat-Klok', 'Dynamische-Globe', 'Voedsel-Innovatie', 'Klimaat-Experience'], 0, $moduleCount);
        $services['plans']->saveManualModuleKeys($id, $modules);
        $rounds = $services['templates']->defaultRounds('dag', $moduleCount);
        $staffByModule = [];
        foreach ($modules as $module) {
            for ($i = 0; $i < ($module === 'Dynamische-Globe' ? 2 : 1); $i++) {
                $insert = $pdo->prepare('INSERT INTO roster_staff_members(display_name,is_active,can_guide,can_cook) VALUES (?,1,1,0)');
                $insert->execute(["V1 {$id} {$module} {$i}"]); $staffId = (int) $pdo->lastInsertId();
                $pdo->prepare('INSERT INTO roster_staff_module_preferences(staff_id,module_key,preference_rank) VALUES (?,?,7)')->execute([$staffId, $module]);
                $staffByModule[$module][] = $staffId;
            }
        }
        $cookId = null;
        if (in_array('Voedsel-Innovatie', $modules, true)) {
            $pdo->prepare('INSERT INTO roster_staff_members(display_name,is_active,can_guide,can_cook) VALUES (?,1,0,1)')->execute(["V1 kok {$id}"]);
            $cookId = (int) $pdo->lastInsertId();
        }
        $services['staff']->savePlanSettings($id, 'with_staff', true, $cookId);
        foreach ($rounds as $index => $round) {
            foreach (array_chunk(array_column($plan['groups'], 'id'), 2) as $pairIndex => $groupIds) {
                $module = $modules[($index + $pairIndex) % $moduleCount];
                $sid = $services['sessions']->insertActivity($id, $module, $round['startTime'], $round['endTime'], null, 1);
                $services['sessions']->replaceGroups($sid, $groupIds);
                $services['staff']->replaceSessionAssignments($sid, $staffByModule[$module], 'manual');
                $services['staff']->addPlanSelection($id, $staffByModule[$module]);
            }
        }
        return $services['service']->getPlan($id);
    }

    public static function assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
}
