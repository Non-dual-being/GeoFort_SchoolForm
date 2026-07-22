<?php
declare(strict_types=1);

use GeoFort\Booking\Rules\BookingRuleOverridePolicy;
use GeoFort\Booking\Rules\BookingRuleOverrideRequest;
use GeoFort\Booking\Rules\BookingRuleSeverity;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$policy = new BookingRuleOverridePolicy();
$assert($policy->definition('INCOMPLETE_CJP_DETAILS')->overridable, 'CJP-regel moet overridable zijn.');
$assert($policy->definition('STUDENT_LIMIT_EXCEEDED')->severity === BookingRuleSeverity::Warning, 'Capaciteitsregel moet warning zijn.');
$assert(!$policy->definition('INVALID_VISIT_DATE')->overridable, 'Ongeldige datum mag niet overridable zijn.');
$assert(!$policy->definition('UNKNOWN_CODE')->overridable, 'Onbekende code moet hard blijven.');
$request = new BookingRuleOverrideRequest('INCOMPLETE_CJP_DETAILS', '  Historische aanvraag zonder opgeslagen pasnummer.  ');
$assert($request->reason === 'Historische aanvraag zonder opgeslagen pasnummer.', 'Reden wordt niet veilig getrimd.');
foreach (['', 'te kort', str_repeat('a', 501)] as $reason) {
    try { new BookingRuleOverrideRequest('INCOMPLETE_CJP_DETAILS', $reason); }
    catch (InvalidArgumentException) { continue; }
    throw new RuntimeException('Ongeldige overridereden is geaccepteerd.');
}

echo "Booking rule override domain tests passed.\n";
