<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GeoFort\Services\Dashboard\Roster\{RosterStaffChange, RosterStaffImpactToken, RosterStaffPreferencePolicy, RosterPlanException};

$assert = static function (bool $value, string $message): void { if (!$value) throw new RuntimeException($message); };
$invalid = static function (callable $call, string $code) use ($assert): void {
    try { $call(); } catch (RosterPlanException $e) { $assert($e->publicCode === $code, $e->publicCode); return; }
    throw new RuntimeException('Expected rejection: ' . $code);
};
$input = ['name' => ' Test Kok ', 'isActive' => true, 'employmentType' => 'paid', 'canGuide' => true, 'canCook' => true,
    'expectedCatalogRevision' => 1, 'preferences' => [['moduleKey' => 'Earth-Watch', 'rank' => 3], ['moduleKey' => 'Dynamische-Globe', 'rank' => 3]]];
$change = RosterStaffChange::fromArray($input, true);
$assert($change->values['name'] === 'Test Kok' && count($change->values['preferences']) === 2, 'Trim and equal ranks');
$ranks = $change->values['preferences'];
$invalid(fn () => RosterStaffPreferencePolicy::validate(null, $ranks), 'DUPLICATE_STAFF_PREFERENCE_RANK');
RosterStaffPreferencePolicy::validate($ranks, array_reverse($ranks));
$invalid(fn () => RosterStaffPreferencePolicy::validate($ranks, [...$ranks, ['moduleKey' => 'Legacy', 'rank' => 4]]), 'DUPLICATE_STAFF_PREFERENCE_RANK');
RosterStaffPreferencePolicy::validate($ranks, [['moduleKey' => 'Earth-Watch', 'rank' => 3], ['moduleKey' => 'Dynamische-Globe', 'rank' => 255]]);
foreach ([
    ['name' => ''], ['name' => str_repeat('é', 121)], ['name' => "line\nbreak"], ['isActive' => 1],
    ['employmentType' => 'unknown'], ['expectedCatalogRevision' => '1'], ['notes' => 'private'],
    ['preferences' => [['moduleKey' => 'unknown', 'rank' => 1]]],
    ['preferences' => [['moduleKey' => 'Earth-Watch', 'rank' => 0]]],
    ['preferences' => [['moduleKey' => 'Earth-Watch', 'rank' => 256]]],
    ['preferences' => [['moduleKey' => 'Earth-Watch', 'rank' => 1], ['moduleKey' => 'Earth-Watch', 'rank' => 2]]],
] as $override) $invalid(fn () => RosterStaffChange::fromArray(array_replace($input, $override), true), 'INVALID_STAFF');
$secret = str_repeat('s', 64); $context = ['actorId' => 1, 'impact' => ['planId' => 1, 'revision' => 3]];
$token = RosterStaffImpactToken::issue($context, $secret, 1800000000);
RosterStaffImpactToken::verify($token, $context, $secret, 1800000001);
$invalid(fn () => RosterStaffImpactToken::verify($token, ['actorId' => 2], $secret, 1800000001), 'STALE_STAFF_IMPACT');
$invalid(fn () => RosterStaffImpactToken::verify($token, $context, str_repeat('x', 64), 1800000001), 'STALE_STAFF_IMPACT');
$invalid(fn () => RosterStaffImpactToken::verify($token, $context, $secret, 1800000301), 'STALE_STAFF_IMPACT');
echo "PASS personnel input, unchanged legacy ties, unique changed ranks 1..255, allowlist, private signed impact/expiry\n";
