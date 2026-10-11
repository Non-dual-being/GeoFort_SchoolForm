<?php
declare(strict_types=1);
namespace GeoFort\Services\Dashboard\Roster;

use GeoFort\Services\Sql\{RosterPairSqlRepository,RosterValidationSqlRepository,RosterSnapshotSqlRepository,RosterPlanSqlRepository};
use PDO;
use LogicException;

/** Runs inside the same catalog-locked transaction as every assignment mutation. */
final class RosterPairGuard
{
    public static function groupCount(int $count): void
    {
        if ($count > 10) throw new RosterPlanException('PAIR_GROUP_LIMIT', 422, "Deze scholen hebben samen {$count} groepen. Een koppeling mag maximaal 10 groepen hebben.");
    }

    public static function groups(PDO $pdo, int $planId): void
    {
        $pair = (new RosterPairSqlRepository($pdo))->forPlan($planId);
        if ($pair === null) return;
        $raw = new RosterValidationSqlRepository($pdo);
        self::groupCount(array_sum(array_map(static fn(int $id): int => count($raw->load($id)['groups']), $pair['planIds'])));
    }

    public static function staff(PDO $pdo, int $planId): void
    {
        $pair=(new RosterPairSqlRepository($pdo))->forPlan($planId);
        if ($pair===null) return;
        if (!$pdo->inTransaction()) throw new LogicException('Joint staff checks require a transaction.');
        self::groups($pdo, $planId);
        $raw=new RosterValidationSqlRepository($pdo);
        $revisions=[];
        foreach ($pair['planIds'] as $id) {
            $data=$raw->load($id);
            if ($data===null || $data['plan']['visit_date']!==$pair['visitDate']) throw new RosterPlanException('PAIR_DATE_MISMATCH',409,'Gekoppelde scholen moeten op dezelfde bezoekdatum blijven.');
            $revisions[$id]=(int)$data['plan']['revision'];
        }
        $validator=new RosterValidationService($raw,new RosterSnapshotSqlRepository($pdo),new RosterPlanningConfig(),new RosterGenerationTemplateProvider());
        $report=$validator->validatePlans($revisions);
        foreach($report['plans'] as $planReport) foreach($planReport['errors'] as $error) {
            if(in_array($error['code'],['STAFF_OVERLAP','STAFF_MODULE_LIMIT','STAFF_NOT_QUALIFIED'],true)) throw new RosterPlanException($error['code'],422,$error['message']);
        }
        foreach ($report['day']['errors'] as $error) {
            if (str_starts_with($error['code'],'STAFF_') || $error['code']==='SHARED_SUPERVISION_CONFIRMATION_REQUIRED') {
                throw new RosterPlanException($error['code'],422,$error['message'].' Controleer beide schoolroosters.');
            }
        }
    }
}
