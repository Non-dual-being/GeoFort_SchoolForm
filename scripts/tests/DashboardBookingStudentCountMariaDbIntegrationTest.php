<?php

declare(strict_types=1);
require __DIR__ . '/Support/RosterLifecycleFixtures.php';

use GeoFort\Booking\Attendance\{BookingAttendanceChangeCommand, BookingAttendanceChangeCode};
use GeoFort\Booking\Status\{BookingStatusChangeCommand, BookingStatusChangeCode, BookingStatusMailMode};
use GeoFort\Booking\ProgramConfiguration\{BookingProgramConfigurationCommand, BookingProgramConfigurationSnapshot, BookingProgramConfigurationCode};
use GeoFort\Booking\Rules\BookingRuleOverrideRequest;
use GeoFort\Booking\Stored\StoredBooking;
use GeoFort\Services\Booking\Pricing\BookingPriceQuote;
use GeoFort\Services\Booking\Status\{BookingStatusChangeServiceFactory, BookingStatusMailSenderInterface};
use GeoFort\Services\Booking\Attendance\BookingAttendanceChangeServiceFactory;
use GeoFort\Services\Booking\ProgramConfiguration\BookingProgramConfigurationServiceFactory;

$db = DisposableBookingMariaDb::create('admin_v1_students');
try {
    RosterLifecycleFixtures::schema($db->pdo); $s = RosterLifecycleFixtures::services($db->pdo); $assert = RosterLifecycleFixtures::assert(...);
    $mail = new class implements BookingStatusMailSenderInterface {
        public int $calls = 0;
        public function sendConfirmation(StoredBooking $booking, BookingPriceQuote $quote): void { $this->calls++; }
        public function sendRejection(StoredBooking $booking): void { $this->calls++; }
    };
    $attendance = (new BookingAttendanceChangeServiceFactory($db->pdo))->create();
    $status = (new BookingStatusChangeServiceFactory($db->pdo, $mail))->create();
    $programs = (new BookingProgramConfigurationServiceFactory($db->pdo))->create();
    foreach ([1, 28, 39, 161, 250, 1001] as $index => $count) {
        $date = (new DateTimeImmutable('2028-03-14'))->modify('+' . ($index * 7) . ' days')->format('Y-m-d');
        $db->pdo->prepare("INSERT INTO aanvragen(status,schoolnaam,land,adres,postcode,plaats,school_telefoonnummer,contactpersoon_telefoonnummer,contactpersoon_voornaam,contactpersoon_achternaam,email,bezoekdatum,cjpPasGebruik,onderwijs_sector,programma,keuzemodule_key,aantal_leerlingen,aantal_begeleiders,voorwaarden_akkoord) VALUES('In optie','V1 School','Nederland','Dijk 1','1234 AB','Plaats','0183123456','0612345678','Jan','Jansen','v1@example.test',?,'nee','primairOnderwijs','dag','Earth-Watch',40,50,1)")->execute([$date]);
        $id = (int) $db->pdo->lastInsertId();
        $db->pdo->prepare('UPDATE aanvragen SET eigen_picknick = 1 WHERE id = ?')->execute([$id]);
        $db->pdo->prepare("INSERT INTO aanvraag_onderwijs_selecties(aanvraag_id,sector_key,level_key,level_position,group_key,group_position) VALUES (?,'primairOnderwijs','regulier',1,'groep7',1)")->execute([$id]);
        $prices = (new GeoFort\Services\Booking\Pricing\BookingPriceSnapshotServiceFactory($db->pdo))->create();
        $db->pdo->beginTransaction();
        $prices->appendUsingActiveVersion($id, GeoFort\Services\Booking\Pricing\BookingPricingInput::fromStoredBooking($s['bookings']->findById($id)), GeoFort\Services\Booking\Pricing\BookingPriceSnapshot::REASON_SUBMISSION);
        $db->pdo->commit();
        $result = $attendance->change(new BookingAttendanceChangeCommand($id, 40, 50, $count, 50, 1));
        $assert($result->code === BookingAttendanceChangeCode::Success, "40 -> {$count}: " . $result->code->value);
        $expected = BookingProgramConfigurationSnapshot::fromBooking($s['bookings']->findById($id));
        $proposed = new BookingProgramConfigurationSnapshot($expected->status, $expected->visitDate, $expected->program, $count + 1, $expected->educationSelection, $expected->choiceModule);
        $programResult = $programs->change(new BookingProgramConfigurationCommand($id, $expected, $proposed, 1));
        $assert($programResult->code === BookingProgramConfigurationCode::Success, 'Program wizard blocked positive count: ' . $programResult->code->value . ' ' . json_encode($programResult->issues));
        $assert($attendance->change(new BookingAttendanceChangeCommand($id, $count + 1, 50, $count, 50, 1))->code === BookingAttendanceChangeCode::Success, 'Could not restore test count.');
        // The existing supervisor policy remains independent. It is not a student-count ceiling.
        $overrides = $count > 800 ? [new BookingRuleOverrideRequest('MINIMUM_SUPERVISORS_NOT_MET', 'Bestaande begeleidersafwijking voor deze test bevestigd.')] : [];
        $result = $status->change(new BookingStatusChangeCommand($id, 'In optie', 'Definitief', 1, BookingStatusMailMode::None, $overrides));
        $assert($result->code === BookingStatusChangeCode::Success, "Definitief {$count}: " . $result->code->value . ' ' . json_encode($result));
        $locked = $s['service']->createFromBooking($id, 1)['plan'];
        $assert($locked['planningMode'] === 'booking_locked' && count($locked['groups']) <= 10, 'Positive count cannot start a booking-locked roster.');
        $raw = $s['raw']->load($locked['id']);
        $assert(!in_array('SOURCE_CHANGED', array_column($s['validator']->validateData($raw, 1)->errors, 'code'), true), 'Fresh booking context differs.');
        $raw['booking']['aantal_leerlingen']++;
        $assert(in_array('SOURCE_CHANGED', array_column($s['validator']->validateData($raw, 1)->errors, 'code'), true), 'Changed booking bypassed review.');
        $s['service']->deleteConcept($locked['id']);
        $roster = RosterLifecycleFixtures::validPlan($db->pdo, $s, $count, 2, 2, $date, $id);
        $bookingBefore = $db->pdo->query("SELECT * FROM aanvragen WHERE id = {$id}")->fetch();
        $historyBefore = (int) $db->pdo->query('SELECT COUNT(*) FROM booking_status_history')->fetchColumn();
        $final = $s['lifecycle']->finalize($roster['id'], 1, 1);
        $assert($final['status'] === 'published', 'Positive-count linked roster rejected.');
        $s['lifecycle']->reopen($roster['id'], 2, 1);
        $assert($db->pdo->query("SELECT * FROM aanvragen WHERE id = {$id}")->fetch() === $bookingBefore, 'Roster lifecycle changed booking.');
        $assert((int) $db->pdo->query('SELECT COUNT(*) FROM booking_status_history')->fetchColumn() === $historyBefore && $mail->calls === 0, 'Roster lifecycle touched booking status/mail.');
    }
    echo "OK: dashboard attendance, program wizard, booking Definitief and roster lifecycle accept 1/28/39/161/250/1001.\n";
} finally { $db->drop(); }
