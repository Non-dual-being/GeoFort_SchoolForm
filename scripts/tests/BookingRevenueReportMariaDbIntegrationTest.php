<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';
require __DIR__.'/Support/DisposableBookingMariaDb.php';

use GeoFort\Services\Dashboard\Booking\Revenue\BookingRevenueCriteria;
use GeoFort\Services\Sql\BookingRevenueReportSqlRepository;

$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$database=DisposableBookingMariaDb::create('revenue');
try{
    $pdo=$database->pdo;
    $insert=$pdo->prepare("INSERT INTO aanvragen(status,schoolnaam,land,adres,postcode,plaats,school_telefoonnummer,contactpersoon_telefoonnummer,contactpersoon_voornaam,contactpersoon_achternaam,email,bezoekdatum,cjpPasGebruik,onderwijs_sector,programma,aantal_leerlingen,voorwaarden_akkoord)VALUES('Definitief',:school,'NL','A','1','P','1','2','V','N','x@example.test',:date,'nee','primairOnderwijs','dag',10,1)");
    $insert->execute([':school'=>'MariaDB',':date'=>'2026-08-01']);$id=(int)$pdo->lastInsertId();
    $snapshot=$pdo->prepare("INSERT INTO booking_price_snapshots(booking_id,sequence_number,previous_snapshot_id,snapshot_reason,calculation_state,pricing_version,currency_code,vat_meaning,vat_basis_points,visit_amount_incl_vat_cents,catering_amount_incl_vat_cents,total_amount_incl_vat_cents,total_amount_excl_vat_cents,vat_amount_cents,canonical_input_json,calculation_details_json,input_checksum,checksum_format_version)VALUES(:id,:seq,:previous,'submission','complete','test','EUR','included',900,1000,100,1100,1000,100,'{}','{}',:checksum,1)");
    $snapshot->execute([':id'=>$id,':seq'=>1,':previous'=>null,':checksum'=>str_repeat('a',64)]);$first=(int)$pdo->lastInsertId();
    $snapshot->execute([':id'=>$id,':seq'=>2,':previous'=>$first,':checksum'=>str_repeat('b',64)]);
    $rows=(new BookingRevenueReportSqlRepository($pdo))->findRows(new BookingRevenueCriteria('2026-08-01','2026-08-01'));
    $assert(count($rows)===1&&(int)$rows[0]['sequence_number']===2,'MariaDB-query dupliceert omzet of selecteert niet het nieuwste snapshot.');
    echo "Booking revenue MariaDB integration tests passed.\n";
}finally{$database->drop();}
