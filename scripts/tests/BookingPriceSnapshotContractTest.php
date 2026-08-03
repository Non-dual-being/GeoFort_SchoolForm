<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$migration=file_get_contents($root.'/database/sql/2026-08-03_create_booking_price_snapshots.sql');
$repository=file_get_contents($root.'/src/Services/Sql/BookingPriceSnapshotSqlRepository.php');
$submission=file_get_contents($root.'/src/Services/Booking/Submission/BookingSubmissionService.php');
$assert(str_contains($migration,'BIGINT UNSIGNED')&&!preg_match('/\b(?:FLOAT|DOUBLE|DECIMAL)\b/i',$migration),'Migration gebruikt geen integer cents.');
foreach(['FOREIGN KEY (booking_id)','ON DELETE RESTRICT','UNIQUE KEY uq_booking_price_snapshots_booking_sequence','canonical_input_json JSON','calculation_details_json JSON'] as $needle)$assert(str_contains($migration,$needle),"Migration mist {$needle}.");
$assert(!str_contains($migration,'INSERT INTO aanvragen')&&!str_contains($migration,'INSERT INTO booking_price_snapshots'),'Migration voert een backfill uit.');
$assert(str_contains($repository,'ORDER BY sequence_number DESC LIMIT 1'),'Nieuwste snapshot is niet deterministisch.');
$assert(!preg_match('/\bUPDATE\s+booking_price_snapshots\b/i',$repository)&&!preg_match('/\bDELETE\s+FROM\s+booking_price_snapshots\b/i',$repository),'Repository is niet append-only.');
$assert(str_contains($submission,'REASON_SUBMISSION')&&strpos($submission,'appendUsingActiveVersion')<strpos($submission,'->commit()'),'Submission en snapshot zijn niet atomair.');
$assert(strpos($submission,'->commit()')<strpos($submission,'sendRequestReceivedMail'),'Aanvraagmail wordt vóór commit gestuurd.');
echo "Booking price snapshot contract tests passed.\n";
