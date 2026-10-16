<?php
declare(strict_types=1);
namespace GeoFort\Services\Sql;
use PDO;
use RuntimeException;

final readonly class StaffDeploymentSqlRepository
{
    public function __construct(private PDO $pdo) {}
    public function plans(string $from, string $to): array
    {
        $q=$this->pdo->prepare("SELECT p.*, a.status AS booking_status FROM roster_plans p LEFT JOIN aanvragen a ON a.id=p.booking_id WHERE p.visit_date BETWEEN ? AND ? AND p.status IN ('concept','published') ORDER BY p.visit_date,p.id");
        $q->execute([$from,$to]);
        $raw=new RosterValidationSqlRepository($this->pdo);$snapshots=new RosterSnapshotSqlRepository($this->pdo);$result=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $plan){
            if($plan['status']==='published'){
                $snapshot=$snapshots->state((int)$plan['id'])['current'];
                if($snapshot===null)throw new RuntimeException('Een definitief rooster mist zijn opgeslagen inhoud.');
                $data=$snapshot['content']['stored'];$occupancy=$snapshot['content']['occupancy'];
            }else{$data=$raw->load((int)$plan['id']);$occupancy=null;}
            $result[]=['plan'=>$plan,'data'=>$data,'occupancy'=>$occupancy];
        }
        return $result;
    }
    public function price(int $bookingId): ?int
    {
        $price=(new BookingPriceSnapshotSqlRepository($this->pdo))->latest($bookingId);
        return $price?->calculationState==='complete' ? $price->totalAmountExclVatCents : null;
    }
    public function staff(): array {return (new RosterStaffSqlRepository($this->pdo))->catalog();}
}
