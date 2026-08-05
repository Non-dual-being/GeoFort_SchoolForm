<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
use GeoFort\Services\Dashboard\Booking\Export\SpreadsheetFormulaEscaper;
use GeoFort\Services\Dashboard\Booking\Revenue\BookingRevenueCsvWriter;
$stream=fopen('php://temp','w+b');(new BookingRevenueCsvWriter(new SpreadsheetFormulaEscaper()))->write($stream,[[
 'id'=>7,'schoolName'=>' =SOM(A1)','visitDate'=>'2026-08-05','status'=>'Definitief','sectorLabel'=>'PO; speciaal','program'=>'Dag "plus"','studentCount'=>20,
 'amounts'=>['totalExclVatCents'=>12345,'vatCents'=>1111,'totalInclVatCents'=>13456],'snapshotState'=>'complete'
]]);rewind($stream);$csv=(string)stream_get_contents($stream);fclose($stream);
if(!str_starts_with($csv,"\xEF\xBB\xBF")||!str_contains($csv,"' =SOM(A1)")||!str_contains($csv,'123,45')||!str_contains($csv,"\r\n")||!str_contains($csv,'"PO; speciaal"'))throw new RuntimeException('CSV BOM, escaping, formulebeveiliging, decimalen of CRLF onjuist.');
echo "Booking revenue CSV domain tests passed.\n";
