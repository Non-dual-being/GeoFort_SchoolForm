<?php

declare(strict_types=1);

require __DIR__ . '/Support/RosterLifecycleFixtures.php';

use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Booking\Status\{BookingStatusChangeCommand, BookingStatusChangeCode, BookingStatusMailMode};
use GeoFort\Services\Booking\Data\StoredBookingMailDataFactory;
use GeoFort\Services\Booking\Pricing\{BookingPriceSnapshotServiceFactory, BookingPricingInput, BookingPriceSnapshot};
use GeoFort\Services\Booking\Presentation\EducationSelectionSummaryFactory;
use GeoFort\Services\Booking\Roster\{BookingRosterResolver, RosterAttachmentResolver, RosterGroupCountResolver};
use GeoFort\Services\Booking\Status\BookingStatusChangeServiceFactory;
use GeoFort\Services\Dashboard\Roster\{RosterSchoolDocumentService, RosterSchoolProjection, RosterSchoolPdfWriter, RosterSchoolPdfRenderer, RosterSchoolDocument, RosterPlanException};
use GeoFort\Services\Mail\{MailInterface, MailConfig, BookingStatusMailSender};
use GeoFort\Services\Mail\Attachments\PublicDocumentAttachmentResolver;
use GeoFort\Services\Mail\Templates\{MailLinks, MailLayout, BookingRequestMailTemplate, BookingRejectionMailTemplate, MailContentBlocks};
use GeoFort\Services\Sql\{RosterSchoolDocumentSqlRepository, RosterSqlService};
use GeoFort\Validation\Validator;

final class SchoolTestTransactionPDO extends PDO
{
    public ?Closure $beforeBegin = null;
    public function beginTransaction(): bool
    {
        $hook = $this->beforeBegin; $this->beforeBegin = null; $hook?->__invoke();
        return parent::beginTransaction();
    }
}

$db = DisposableBookingMariaDb::create('roundb_documents');
$assert = RosterLifecycleFixtures::assert(...);
$root = dirname(__DIR__, 2);
$output = getenv('ROSTER_ROUND_B_OUTPUT') ?: sys_get_temp_dir() . '/geofort-roundb-mail-test';
if (!is_dir($output)) mkdir($output, 0700, true);
$migration = file_get_contents($root . '/database/sql/2026-10-03_create_roster_school_documents.sql');
$rollback = file_get_contents($root . '/database/sql/2026-10-03_rollback_roster_school_documents.sql');
try {
    RosterLifecycleFixtures::schema($db->pdo);
    $db->pdo->exec($migration); $db->pdo->exec($rollback); // Empty foundation + rollback.
    $s = RosterLifecycleFixtures::services($db->pdo);
    $sequence = 0;
    $book = static function () use ($db, $s, &$sequence): int {
        $date = (new DateTimeImmutable('2028-10-10'))->modify('+' . (7 * $sequence++) . ' days')->format('Y-m-d');
        $db->pdo->prepare("INSERT INTO aanvragen(status,schoolnaam,land,adres,postcode,plaats,school_telefoonnummer,contactpersoon_telefoonnummer,contactpersoon_voornaam,contactpersoon_achternaam,email,bezoekdatum,cjpPasGebruik,onderwijs_sector,programma,keuzemodule_key,aantal_leerlingen,aantal_begeleiders,voorwaarden_akkoord,eigen_picknick) VALUES('In optie','School & Één Wereld','Nederland','Dijk 1','1234 AB','Plaats','0183123456','0612345678','Jan','Jansen','school@example.test',?,'nee','primairOnderwijs','dag','Earth-Watch',40,8,1,1)")->execute([$date]);
        $id = (int) $db->pdo->lastInsertId();
        $db->pdo->prepare("INSERT INTO aanvraag_onderwijs_selecties(aanvraag_id,sector_key,level_key,level_position,group_key,group_position) VALUES (?,'primairOnderwijs','regulier',1,'groep7',1)")->execute([$id]);
        $prices = (new BookingPriceSnapshotServiceFactory($db->pdo))->create();
        $db->pdo->beginTransaction();
        $prices->appendUsingActiveVersion($id, BookingPricingInput::fromStoredBooking($s['bookings']->findById($id)), BookingPriceSnapshot::REASON_SUBMISSION);
        $db->pdo->commit();
        return $id;
    };
    $planFor = static fn (int $id): array => RosterLifecycleFixtures::validPlan($db->pdo, $s, 40, 2, 2, $s['bookings']->findById($id)->visitDate, $id);
    $id = $book(); $plan = $planFor($id); $s['lifecycle']->finalize($plan['id'], 1, 1);
    $hashBefore = $db->pdo->query('SELECT content_hash FROM roster_plan_snapshots')->fetchColumn();
    $db->pdo->exec($migration); // Existing populated foundation: preserves snapshots.
    $assert($db->pdo->query('SELECT content_hash FROM roster_plan_snapshots')->fetchColumn() === $hashBefore, 'Migration changed history');
    $repository = new RosterSchoolDocumentSqlRepository($db->pdo);
    $renderer = new class implements RosterSchoolPdfRenderer {
        public int $calls = 0;
        public function render(array $school): string { $this->calls++; return (new RosterSchoolPdfWriter())->render($school); }
    };
    $documents = new RosterSchoolDocumentService($repository, $s['snapshots'], $s['bookings'], new RosterSchoolProjection(), $renderer);
    $meta = $documents->available($id);
    $assert($meta !== null && $meta['revision'] === 2 && array_keys($meta) === ['snapshotId', 'revision', 'finalizedAt', 'visitDate'], 'Unsafe/missing metadata');
    $assert((int) $db->pdo->query('SELECT COUNT(*) FROM roster_school_documents')->fetchColumn() === 0, 'Metadata endpoint wrote a document');
    $snapshotId = $meta['snapshotId'];
    $databaseState = static function () use ($db): array {
        $state = [];
        foreach ($db->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            if (!preg_match('/^[a-z0-9_]+$/D', $table)) throw new RuntimeException('Invalid fixture table');
            $rows = array_map(serialize(...), $db->pdo->query('SELECT * FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC));
            sort($rows); $state[$table] = $rows;
        }
        ksort($state);
        return $state;
    };
    // Execute the real private HTTP controller with the real session guard and disposable SQL services.
    $auth = new GeoFort\Security\AuthMiddleware('round_b_test', 'Strict', false);
    $guard = new GeoFort\Security\SessionGuard(new GeoFort\Services\Sql\AdminUsersSqlService($db->pdo, 5), 600, 60);
    $response = new GeoFort\Services\Http\Response\JsonResponse(new GeoFort\Services\Http\Url\EnvironmentBaseUrlProvider('development', 'https://onderwijsformulier.test'));
    $action = new GeoFort\Services\Http\Api\Admin\DashboardRosterSchoolDocumentAction($auth, $guard, $documents, $response);
    $csrf = new GeoFort\Services\Auth\CsrfTokenService();
    $prepareAction = new GeoFort\Services\Http\Api\Admin\DashboardRosterPrepareDocumentAction($auth, $guard, $csrf, $documents, $response);
    $post = static function (string $body, ?string $token = null, string $method = 'POST', string $contentType = 'application/json') use ($prepareAction): array {
        http_response_code(200); ob_start();
        try { $prepareAction->send($method, $contentType, $token, $body, 'Round B test'); return [http_response_code(), json_decode(ob_get_contents(), true, 32, JSON_THROW_ON_ERROR)]; }
        finally { ob_end_clean(); }
    };
    $postBody = json_encode(['bookingId' => $id, 'snapshotId' => $snapshotId], JSON_THROW_ON_ERROR);
    $http = static function (array $query, bool $download = false, string $method = 'GET') use ($action, $db): array {
        // Any attempted database write must fail, even an UPDATE that leaves values unchanged.
        $db->pdo->exec('START TRANSACTION READ ONLY');
        http_response_code(200); ob_start();
        try { $action->send($method, $query, $download, 'Round B test'); return [http_response_code(), ob_get_contents()]; }
        finally { ob_end_clean(); $db->pdo->rollBack(); }
    };
    $assert($http(['bookingId' => (string) $id])[0] === 401 && $http(['bookingId' => (string) $id, 'snapshotId' => (string) $snapshotId], true)[0] === 401, 'Private endpoints allowed anonymous session');
    $assert($post($postBody)[0] === 401, 'Prepare POST allowed anonymous session');
    $auth->establishAuthenticatedSession(['id'=>1,'email'=>'admin@example.test','name'=>'Admin','role'=>'admin'], null, 'Round B test');
    $_SESSION['last_revalidation_time'] = 0; // Exercise the real auth database read as well.
    $token = $csrf->getOrCreate(GeoFort\Services\Http\Api\Admin\DashboardRosterPrepareDocumentAction::CSRF_SCOPE);
    $bootstrap = (new GeoFort\Services\Dashboard\DashboardBootstrapService($csrf, 'development'))->build()->toArray();
    $assert($bootstrap['rosterDocumentCsrfToken'] === $token && $token !== $bootstrap['bookingStatusCsrfToken'] && $token !== $bootstrap['logoutCsrfToken'], 'Dashboard bootstrap must supply the dedicated prepare token');
    $oldState = $databaseState();
    $assert($post($postBody)[0] === 403 && $post($postBody, $csrf->getOrCreate('update-booking-status'))[0] === 403, 'Prepare accepted missing/wrong-scope CSRF');
    $assert($post($postBody, $token, 'GET')[0] === 405, 'Prepare accepts GET');
    $assert($post($postBody, $token, 'POST', 'application/json-invalid')[0] === 400, 'Prepare accepts invalid Content-Type');
    foreach (['null', '[]', '{}', 'true', '{', '{"bookingId":1}'] as $badBody) $assert($post($badBody, $token)[0] === 422, 'Prepare accepted invalid JSON shape');
    foreach (['bookingId', 'snapshotId'] as $field) foreach ([null, true, 0, -1, '1', 1.0, 1.5, [], PHP_INT_MAX . '0'] as $badValue) {
        $invalid = ['bookingId' => $id, 'snapshotId' => $snapshotId]; $invalid[$field] = $badValue;
        $assert($post(json_encode($invalid, JSON_PRESERVE_ZERO_FRACTION), $token)[0] === 422, 'Prepare accepted non-strict ID');
    }
    $assert($post(json_encode(['bookingId'=>$id, 'snapshotId'=>$snapshotId, 'path'=>'/private.pdf']), $token)[0] === 422, 'Prepare accepts client path');
    $assert($http(['bookingId' => (string) $id], false, 'POST')[0] === 405, 'Private read accepts POST');
    foreach (['01', '1e0', '-1', '0', ['1'], '99999999999999999999999'] as $badId) $assert($http(['bookingId' => $badId])[0] === 422, 'Loose read ID');
    $assert($http(['bookingId' => (string) $id, 'path' => '/private.pdf'])[0] === 422, 'Client path accepted');
    [$httpStatus, $body] = $http(['bookingId' => (string) $id]);
    $assert($httpStatus === 200 && json_decode($body, true)['data']['plannerFinal'] === $meta, 'Metadata HTTP contract mismatch');
    [$httpStatus, $body] = $http(['bookingId' => (string) $id, 'snapshotId' => (string) $snapshotId], true);
    $assert($httpStatus === 409 && json_decode($body, true)['code'] === 'ROSTER_DOCUMENT_NOT_PREPARED', 'GET before prepare did not fail clearly');
    $assert($databaseState() === $oldState && $renderer->calls === 0, 'Metadata/GET/invalid POST wrote data or rendered PDF');
    [$httpStatus, $prepared] = $post($postBody, $token);
    $assert($httpStatus === 200 && $prepared['ok'], 'Prepare POST failed');
    $assert(array_keys($prepared['data']['document']) === ['snapshotId', 'filename', 'mimeType', 'sha256', 'byteLength'], 'Prepare leaked unsafe metadata');
    $assert((int) $db->pdo->query('SELECT COUNT(*) FROM roster_school_documents')->fetchColumn() === 1 && $renderer->calls === 1, 'Prepare must create exactly one document');
    $document = $documents->readPrepared($id, $snapshotId);
    $assert($document->templateVersion === 'school-v2', 'New document must use school-v2');
    $assert($prepared['data']['document']['sha256'] === $document->hash && $prepared['data']['document']['byteLength'] === strlen($document->bytes), 'Prepare metadata mismatch');
    $createdState = $databaseState();
    $assert($post($postBody, $token) === [$httpStatus, $prepared], 'Repeated prepare returns different metadata/hash');
    $assert($databaseState() === $createdState && $renderer->calls === 1 && $documents->readPrepared($id, $snapshotId)->bytes === $document->bytes, 'Repeated prepare changed database fields/bytes or rendered again');
    [$httpStatus, $downloaded] = $http(['bookingId' => (string) $id, 'snapshotId' => (string) $snapshotId], true);
    $assert($httpStatus === 200 && $downloaded === $document->bytes, 'Private download differs from stored document');
    $assert($http(['bookingId' => (string) $id, 'snapshotId' => (string) $snapshotId], true) === [200, $downloaded], 'Repeated GET changes bytes');
    $assert($http(['bookingId' => (string) $id])[0] === 200 && $databaseState() === $createdState && $renderer->calls === 1, 'Repeated GET changes database fields or renders');
    $assert($http(['bookingId' => (string) $id, 'snapshotId' => '999999'], true)[0] === 409, 'Stale HTTP download accepted');
    session_write_close();
    $assert(hash('sha256', $document->bytes) === $document->hash, 'Stored PDF hash mismatch');
    $assert($documents->ensurePrepared($id, $snapshotId)->bytes === $document->bytes, 'Defensive preparation changed bytes');
    $newBytes = $document->bytes . "\n%alternate render";
    $winner = $repository->append(new RosterSchoolDocument($snapshotId, $document->snapshotHash, 'future-template', $document->filename, $newBytes, hash('sha256', $newBytes)));
    $assert($winner->bytes === $document->bytes, 'Competing render overwrote immutable bytes');
    foreach (['UPDATE roster_school_documents SET template_version = template_version', 'DELETE FROM roster_school_documents'] as $sql) {
        try { $db->pdo->exec($sql); throw new LogicException('Document mutation allowed'); } catch (PDOException) {}
    }
    $db->pdo->exec("UPDATE roster_staff_members SET display_name=CONCAT('Later gewijzigde naam ', id)");
    $db->pdo->prepare("UPDATE aanvragen SET schoolnaam='Later gewijzigde school' WHERE id=?")->execute([$id]);
    $throwingRenderer = new class implements RosterSchoolPdfRenderer {
        public function render(array $school): string { throw new RuntimeException('Injected renderer failure'); }
    };
    $future = new RosterSchoolDocumentService($repository, $s['snapshots'], $s['bookings'], new RosterSchoolProjection(), $throwingRenderer);
    $assert($future->ensurePrepared($id, $snapshotId)->bytes === $document->bytes, 'Existing PDF was re-rendered');
    $without = $book();
    $assert($documents->available($without) === null, 'Missing roster eligible');
    $draft = $planFor($without);
    $assert($documents->available($without) === null, 'Concept eligible');
    $db->pdo->prepare("UPDATE roster_plans SET status='published' WHERE id=?")->execute([$draft['id']]);
    $assert($documents->available($without) === null, 'Legacy published eligible');
    $db->pdo->prepare("UPDATE roster_plans SET status='concept' WHERE id=?")->execute([$draft['id']]);
    foreach ([[$without, $snapshotId], [$id, PHP_INT_MAX]] as [$bookingId, $sid]) {
        try { $documents->ensurePrepared($bookingId, $sid); throw new LogicException('Foreign/manipulated ID allowed'); } catch (RosterPlanException $e) { $assert($e->httpStatus === 409, 'Wrong stale code'); }
        $assert($post(json_encode(['bookingId'=>$bookingId, 'snapshotId'=>$sid]), $token)[0] === 409, 'Prepare POST accepts foreign/manipulated snapshot');
        $assert($http(['bookingId'=>(string)$bookingId, 'snapshotId'=>(string)$sid], true)[0] === 409, 'Download GET accepts foreign/manipulated snapshot');
    }

    // Real mail composer, capturing transport: no SMTP object and no network mail.
    $mail = new class implements MailInterface {
        public array $calls = []; public bool $fail = false;
        public function send(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody, array $cc = [], array $attachments = [], array $bcc = []): void {
            if ($this->fail) throw new RuntimeException('Injected SMTP failure');
            $this->calls[] = compact('toEmail', 'subject', 'htmlBody', 'textBody', 'cc', 'attachments', 'bcc');
        }
    };
    $public = $output . '/fake-public';
    foreach (['assets/booking/roosters/pdf', 'assets/booking/documents'] as $path) if (!is_dir($public . '/' . $path)) mkdir($public . '/' . $path, 0700, true);
    file_put_contents($public . '/assets/booking/roosters/pdf/example.pdf', $document->bytes);
    file_put_contents($public . '/assets/booking/documents/Routekaart_Bussen_GeoFort_Onderwijs.pdf', $document->bytes);
    $db->pdo->exec('CREATE TABLE roosters(id INT PRIMARY KEY,schooltype VARCHAR(80),keuzemodule VARCHAR(100),leerlingen_min INT,leerlingen_max INT,programmaduur VARCHAR(20),afbeelding VARCHAR(255),pdf VARCHAR(255))');
    $db->pdo->prepare("INSERT INTO roosters VALUES (1,?,'Earth-Watch',40,160,'dag','images/example.png','pdf/example.pdf')")->execute([BookingProgramConfig::getSchoolSectorConfig('primairOnderwijs')['roosterType']]);
    $links = new MailLinks('https://example.test', 'https://example.test/voorwaarden', 'onderwijs@example.test');
    $layout = new MailLayout($links);
    $sender = new BookingStatusMailSender($mail,
        new MailConfig('', 0, '', '', '', 'sender@example.test', 'GeoFort', 'planner@example.test', null, ['cc@example.test'], 'production', 0, ['request-bcc@example.test']),
        new BookingRequestMailTemplate($layout, $links, new Validator(), new EducationSelectionSummaryFactory()),
        new BookingRejectionMailTemplate($layout, $links, new MailContentBlocks($links)), new StoredBookingMailDataFactory(),
        new BookingRosterResolver(new RosterSqlService($db->pdo), new RosterGroupCountResolver()), new RosterAttachmentResolver($public), new PublicDocumentAttachmentResolver($public));
    $status = (new BookingStatusChangeServiceFactory($db->pdo, $sender, $documents))->create();
    $command = static fn (int $bid, ?int $sid = null): BookingStatusChangeCommand => new BookingStatusChangeCommand($bid, 'In optie', 'Definitief', 1, BookingStatusMailMode::Send, [], $sid === null ? 'example' : 'planner_final', $sid);
    $exampleBooking = $book(); $result = $status->change($command($exampleBooking));
    $assert($result->code === BookingStatusChangeCode::Success, 'Example confirmation failed: ' . $result->code->value);
    $example = $mail->calls[0];
    $assert(array_column($example['attachments'], 'filename') === ['GeoFort_Onderwijs_Conceptrooster.pdf', 'GeoFort_Route_en_Parkeren_bus.pdf'], 'Example route changed');
    $result = $status->change($command($id, $snapshotId));
    $assert($result->code === BookingStatusChangeCode::Success, 'Planner confirmation failed: ' . $result->code->value);
    $final = $mail->calls[1];
    $assert(array_column($final['attachments'], 'filename') === [$document->filename, 'GeoFort_Route_en_Parkeren_bus.pdf'], 'Double/missing roster attachment');
    $assert($final['attachments'][0]->bytes === $downloaded && $downloaded === $document->bytes && hash('sha256', $final['attachments'][0]->bytes) === $prepared['data']['document']['sha256'], 'Prepare, download and mail differ');
    foreach (['htmlBody', 'textBody'] as $format) $assert(str_contains($final[$format], 'door onze GeoFort-planner voor uw school opgesteld') && !str_contains($final[$format], 'conceptrooster'), 'Wrong planner mail text');
    $assert($final['cc'] === ['cc@example.test'] && $final['bcc'] === [] && $final['toEmail'] === 'school@example.test', 'Recipient/CC/BCC changed');
    file_put_contents($output . '/captured-planner-mail.html', $final['htmlBody']);
    file_put_contents($output . '/captured-planner-mail.txt', $final['textBody']);
    file_put_contents($output . '/captured-planner-attachment.pdf', $final['attachments'][0]->bytes);

    require __DIR__ . '/Support/JointSchoolDocumentCases.php';

    $s['lifecycle']->reopen($plan['id'], 2, 1);
    $assert($documents->available($id) === null, 'Reopened snapshot available');
    try { $documents->ensurePrepared($id, $snapshotId); throw new LogicException('Old snapshot allowed'); } catch (RosterPlanException) {}
    $assert($post($postBody, $token)[0] === 409 && $http(['bookingId'=>(string)$id,'snapshotId'=>(string)$snapshotId], true)[0] === 409, 'Reopened snapshot allowed by POST/GET');
    $s['lifecycle']->finalize($plan['id'], 3, 1);
    $newId = $documents->available($id)['snapshotId'];
    $assert($newId !== $snapshotId && $documents->ensurePrepared($id, $newId)->snapshotId === $newId, 'New final lacks own document');
    $assert($repository->find($snapshotId)->bytes === $document->bytes, 'Old document changed on new final');

    // A real, frozen v1 fixture remains valid under the v2 renderer, both current and historical.
    $v1Booking = $book(); $v1Plan = $planFor($v1Booking); $s['lifecycle']->finalize($v1Plan['id'], 1, 1);
    $v1SnapshotId = $documents->available($v1Booking)['snapshotId'];
    $v1Snapshot = $s['snapshots']->find($v1Plan['id'], $v1SnapshotId);
    $v1Bytes = file_get_contents(__DIR__ . '/fixtures/roster-school-v1.pdf');
    $v1 = $repository->append(new RosterSchoolDocument($v1SnapshotId, $v1Snapshot['content_hash'], 'school-v1',
        'GeoFort_Definitief_Lesrooster_V1.pdf', $v1Bytes, hash('sha256', $v1Bytes)));
    $v1State = $databaseState();
    $v1Body = json_encode(['bookingId' => $v1Booking, 'snapshotId' => $v1SnapshotId]);
    $assert($future->ensurePrepared($v1Booking, $v1SnapshotId)->bytes === $v1Bytes, 'Current v1 rendered or upgraded');
    $assert($future->readPrepared($v1Booking, $v1SnapshotId)->templateVersion === 'school-v1', 'Current v1 no longer readable');
    $assert($post($v1Body, $token)[1]['data']['document']['sha256'] === $v1->hash, 'Prepare upgraded current v1');
    $assert($http(['bookingId' => (string) $v1Booking, 'snapshotId' => (string) $v1SnapshotId], true) === [200, $v1Bytes], 'GET cannot read current v1');
    $assert($databaseState() === $v1State, 'Reading/repreparing v1 changed database values');
    $s['lifecycle']->reopen($v1Plan['id'], 2, 1);
    $s['lifecycle']->finalize($v1Plan['id'], 3, 1);
    $v2SnapshotId = $documents->available($v1Booking)['snapshotId'];
    $v2 = $documents->ensurePrepared($v1Booking, $v2SnapshotId);
    $assert($v2SnapshotId !== $v1SnapshotId && $v2->templateVersion === 'school-v2', 'New finalization did not produce independent v2');
    $assert($repository->find($v1SnapshotId)->bytes === $v1Bytes && $repository->find($v1SnapshotId)->hash === $v1->hash, 'Historical v1 bytes/hash changed');
    $assert($s['snapshots']->find($v1Plan['id'], $v1SnapshotId) === $v1Snapshot, 'Historical v1 snapshot changed');
    file_put_contents($output . '/document-versions.json', json_encode(['currentV1ReusedWithoutWriteOrRender' => true,
        'historicalV1BytesUnchanged' => true, 'v1Sha256' => $v1->hash, 'v1SnapshotId' => $v1SnapshotId,
        'newV2SnapshotId' => $v2SnapshotId, 'newV2Sha256' => $v2->hash, 'newV2Template' => $v2->templateVersion], JSON_PRETTY_PRINT));

    // Renderer, stored-document hash, snapshot hash, and a reopen immediately before transaction all keep status/history/pricing intact.
    $failBooking = $book(); $failPlan = $planFor($failBooking); $s['lifecycle']->finalize($failPlan['id'], 1, 1);
    $failId = $documents->available($failBooking)['snapshotId'];
    $before = static fn (): array => [$s['bookings']->findById($failBooking)->status,
        (int) $db->pdo->query('SELECT COUNT(*) FROM booking_status_history')->fetchColumn(),
        (int) $db->pdo->query('SELECT COUNT(*) FROM booking_price_snapshots')->fetchColumn(), count($mail->calls)];
    $original = $before();
    $failed = (new BookingStatusChangeServiceFactory($db->pdo, $sender, $future))->create()->change($command($failBooking, $failId));
    $assert($failed->code === BookingStatusChangeCode::RosterAttachmentFailed && $before() === $original, 'Render failure mutated status');
    $good = $documents->ensurePrepared($failBooking, $failId);
    $db->pdo->exec('DROP TRIGGER roster_school_document_no_update');
    $db->pdo->prepare("UPDATE roster_school_documents SET content_hash=REPEAT('0',64) WHERE snapshot_id=?")->execute([$failId]);
    $failed = $status->change($command($failBooking, $failId));
    $assert($failed->code === BookingStatusChangeCode::RosterAttachmentFailed && $before() === $original, 'Corrupt document mutated status');
    $assert($http(['bookingId'=>(string)$failBooking,'snapshotId'=>(string)$failId], true)[0] === 500, 'GET accepted corrupt document hash');
    $db->pdo->prepare('UPDATE roster_school_documents SET content_hash=? WHERE snapshot_id=?')->execute([$good->hash, $failId]);
    $db->pdo->prepare("UPDATE roster_school_documents SET snapshot_hash=REPEAT('0',64) WHERE snapshot_id=?")->execute([$failId]);
    $failed = $status->change($command($failBooking, $failId));
    $assert($failed->code === BookingStatusChangeCode::RosterAttachmentFailed && $before() === $original, 'Document/snapshot mismatch mutated status');
    $assert($http(['bookingId'=>(string)$failBooking,'snapshotId'=>(string)$failId], true)[0] === 500, 'GET accepted document/snapshot mismatch');
    $db->pdo->prepare('UPDATE roster_school_documents SET snapshot_hash=? WHERE snapshot_id=?')->execute([$good->snapshotHash, $failId]);
    $db->pdo->exec('DROP TRIGGER roster_snapshot_no_update');
    $db->pdo->prepare("UPDATE roster_plan_snapshots SET content_hash=REPEAT('0',64) WHERE id=?")->execute([$failId]);
    $failed = $status->change($command($failBooking, $failId));
    $assert($failed->code === BookingStatusChangeCode::RosterAttachmentUnavailable && $before() === $original, 'Corrupt snapshot mutated status');
    $assert($http(['bookingId'=>(string)$failBooking,'snapshotId'=>(string)$failId], true)[0] === 409, 'GET accepted corrupt snapshot');
    $db->pdo->prepare('UPDATE roster_plan_snapshots SET content_hash=? WHERE id=?')->execute([$good->snapshotHash, $failId]);
    $hookPdo = new SchoolTestTransactionPDO('mysql:host='.getenv('STATUS_TEST_DB_HOST').';port='.getenv('STATUS_TEST_DB_PORT').';dbname='.$db->database.';charset=utf8mb4', getenv('STATUS_TEST_DB_USER'), getenv('STATUS_TEST_DB_PASSWORD'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
    $hookServices = RosterLifecycleFixtures::services($hookPdo);
    $hookDocs = new RosterSchoolDocumentService(new RosterSchoolDocumentSqlRepository($hookPdo), $hookServices['snapshots'], $hookServices['bookings'], new RosterSchoolProjection(), new RosterSchoolPdfWriter());
    $hookPdo->beforeBegin = static function () use ($s, $failPlan): void { $s['lifecycle']->reopen($failPlan['id'], 2, 1); };
    $failed = (new BookingStatusChangeServiceFactory($hookPdo, $sender, $hookDocs))->create()->change($command($failBooking, $failId));
    $assert($failed->code === BookingStatusChangeCode::RosterAttachmentUnavailable && $before() === $original, 'Reopen race mutated status');
    // No-mail action needs no snapshot and remains usable on the reopened plan.
    $none = $status->change(new BookingStatusChangeCommand($failBooking, 'In optie', 'Definitief', 1, BookingStatusMailMode::None));
    $assert($none->code === BookingStatusChangeCode::Success && count($mail->calls) === $original[3], 'No-mail route changed');
    $smtpBooking = $book(); $smtpPlan = $planFor($smtpBooking); $s['lifecycle']->finalize($smtpPlan['id'], 1, 1);
    $mail->fail = true;
    $smtp = $status->change($command($smtpBooking, $documents->available($smtpBooking)['snapshotId']));
    $assert($smtp->code === BookingStatusChangeCode::MailSendFailed && $smtp->success && $s['bookings']->findById($smtpBooking)->status === 'Definitief', 'Existing post-commit SMTP semantics changed');
    $db->pdo->exec($rollback);
    $assert($s['snapshots']->find($plan['id'], $snapshotId)['content_hash'] === $document->snapshotHash, 'Disposable schema rollback changed snapshots');
    file_put_contents($output . '/http-contract.json', json_encode([
        'passed' => true, 'databaseVersion' => $db->pdo->query('SELECT VERSION()')->fetchColumn(),
        'getExecutedInReadOnlyTransactions' => true, 'metadataGetAllDatabaseFieldsUnchanged' => true,
        'missingPdfGetStatus' => 409, 'missingPdfGetRenderCalls' => 0, 'missingPdfGetAllDatabaseFieldsUnchanged' => true,
        'preparePostDocumentCount' => 1, 'secondPrepareAllDatabaseFieldsUnchanged' => true,
        'repeatedPdfGetAllDatabaseFieldsUnchanged' => true, 'prepareResponse' => $prepared,
        'downloadSha256' => hash('sha256', $downloaded), 'mailSha256' => hash('sha256', $final['attachments'][0]->bytes),
        'previewDownloadMailBytesIdentical' => $downloaded === $document->bytes && $downloaded === $final['attachments'][0]->bytes,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "PASS: read-only metadata/PDF GETs with full database-field comparison; missing GET returns 409 without render/write; scoped-CSRF prepare POST, strict IDs, exactly one immutable document, repeated POST/GET identical bytes/hash, fake mail equality; migrations, failures, race and SMTP semantics.\n";
} finally { $db->drop(); }
