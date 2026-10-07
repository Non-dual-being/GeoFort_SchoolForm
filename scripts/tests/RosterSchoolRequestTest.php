<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GeoFort\Services\Http\Api\Admin\{BookingStatusUpdateRequest, BookingStatusUpdateRequestException, BookingStatusUpdateResponseMapper};
use GeoFort\Booking\Status\BookingStatusChangeCode;

$base = ['bookingId' => 1, 'expectedCurrentStatus' => 'In optie', 'targetStatus' => 'Definitief', 'mailMode' => 'send'];
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$parse = static fn (array $body): BookingStatusUpdateRequest => BookingStatusUpdateRequest::fromJson(json_encode($body, JSON_THROW_ON_ERROR));
$assert($parse($base)->rosterAttachmentMode === 'example', 'Old client lost default');
$assert($parse($base + ['rosterAttachmentMode' => 'example'])->rosterSnapshotId === null, 'Example requires snapshot');
$valid = $parse($base + ['rosterAttachmentMode' => 'planner_final', 'rosterSnapshotId' => 12]);
$assert($valid->rosterSnapshotId === 12, 'Planner ID lost');
$assert($parse(array_replace($base, ['mailMode' => 'none']))->rosterSnapshotId === null, 'No-mail requires snapshot');
$invalid = [['rosterAttachmentMode' => 'planner_final'], ['rosterAttachmentMode' => null], ['rosterAttachmentMode' => []], ['rosterAttachmentMode' => 'unknown'], ['rosterSnapshotId' => 12]];
foreach ([null, '12', true, [], 1.5, 0, -1] as $id) $invalid[] = ['rosterAttachmentMode' => 'planner_final', 'rosterSnapshotId' => $id];
$invalid[] = ['rosterAttachmentMode' => 'planner_final', 'rosterSnapshotId' => 12, 'mailMode' => 'none'];
$invalid[] = ['rosterAttachmentMode' => 'planner_final', 'rosterSnapshotId' => 12, 'targetStatus' => 'Afgewezen'];
foreach ($invalid as $overrides) {
    try { $parse(array_replace($base, $overrides)); throw new LogicException('Invalid attachment request accepted'); }
    catch (BookingStatusUpdateRequestException $e) { $assert($e->publicCode === 'INVALID_ROSTER_ATTACHMENT', 'Wrong request code'); }
}
foreach (['path', 'filename', 'html', 'url'] as $field) {
    try { $parse($base + [$field => '/private/document.pdf']); throw new LogicException('Client resource accepted'); }
    catch (BookingStatusUpdateRequestException $e) { $assert($e->publicCode === 'INVALID_REQUEST', 'Wrong resource rejection'); }
}
$mapper = new BookingStatusUpdateResponseMapper();
$assert($mapper->httpStatus(BookingStatusChangeCode::InvalidRosterAttachment) === 422 && $mapper->httpStatus(BookingStatusChangeCode::RosterAttachmentUnavailable) === 409, 'Wrong HTTP status');
echo "PASS: old clients, no mail, strict attachment types, no resource/path input and explicit stale failure.\n";
