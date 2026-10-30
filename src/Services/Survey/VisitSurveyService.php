<?php
declare(strict_types=1);
namespace GeoFort\Services\Survey;

use DateTimeImmutable;
use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Booking\BookingProgramVariant;
use GeoFort\Services\Mail\MailInterface;
use GeoFort\Services\Mail\Templates\MailLayout;
use GeoFort\Services\Mail\Templates\VisitSurveyMailTemplate;
use GeoFort\Services\Sql\VisitSurveySqlRepository;
use InvalidArgumentException;

final readonly class VisitSurveyService
{
    public function __construct(private VisitSurveySqlRepository $repository) {}

    public function saveSettings(array $input, int $actor, DateTimeImmutable $now): void
    {
        $old = $this->repository->settings();
        $enabled = $input['enabled'] ?? null; $delay = $input['delayDays'] ?? null;
        $start = $input['startDate'] ?? null; $revision = $input['revision'] ?? null;
        if (!is_bool($enabled) || !is_int($delay) || $delay < 1 || $delay > 30 || !is_int($revision) || $actor < 1) {
            throw new InvalidArgumentException('Kies een vertraging van 1 tot en met 30 dagen.');
        }
        if ($start !== null) {
            if (!is_string($start)) throw new InvalidArgumentException('Kies een startdatum.');
            VisitSurveyPolicy::date($start);
            if ($start !== $old['startDate'] && $start < $now->format('Y-m-d')) throw new InvalidArgumentException('Een nieuwe startdatum mag niet in het verleden liggen.');
        }
        if ($enabled && ($start === null || (!$old['enabled'] && $start < $now->format('Y-m-d')))) {
            throw new InvalidArgumentException('Kies bij inschakelen een startdatum vanaf vandaag. Zo ontvangen oude bezoeken geen onverwachte uitnodiging.');
        }
        if (!$this->repository->saveSettings($enabled, $delay, $start, $revision, $actor)) {
            throw new InvalidArgumentException('De instellingen zijn intussen gewijzigd. Vernieuw de pagina.');
        }
    }

    public function exclude(int $id, int $actor): void
    {
        if ($id < 1 || $actor < 1) throw new InvalidArgumentException('Ongeldige aanvraag.');
        $this->repository->withLock($id, fn () => $this->repository->exclude($id, $actor));
    }

    private function snapshot(array $row): array
    {
        if ($row['snapshot_json']) return json_decode($row['snapshot_json'], true, 32, JSON_THROW_ON_ERROR);
        $sector = match ($row['onderwijs_sector']) {
            'primairOnderwijs' => in_array('speciaal', $this->repository->educationLevel((int) $row['id']), true) ? 'so' : 'po',
            'voortgezetOnderbouw' => 'vo-lower', 'voortgezetBovenbouw' => 'vo-upper', default => 'unknown',
        };
        $modules = array_map(static fn (string $key): array => ['key' => $key, 'label' => BookingProgramConfig::MODULE_LABELS[$key] ?? $key], $this->repository->modules((int) $row['id']));
        return ['school' => $row['schoolnaam'], 'visitDate' => $row['bezoekdatum'], 'sector' => $sector,
            'variant' => BookingProgramVariant::key($row['programma'], $row['program_version']), 'modules' => $modules];
    }

    private function state(array $row, array $settings, array $snapshot, DateTimeImmutable $now): string
    {
        if ($row['withdrawn_at']) return 'withdrawn';
        if ($row['invitation_status']) return $row['invitation_status'];
        if ($row['excluded']) return 'excluded';
        if (preg_match('/PLANNERTEST|\bTEST\b|TESTAANVRAAG/iu', $row['schoolnaam'])) return 'test';
        if ($row['status'] !== 'Definitief') return 'not-confirmed';
        if ($settings['startDate'] === null || $row['bezoekdatum'] < $settings['startDate']) return 'before-start';
        if (!hash_equals($row['email_hash'], VisitSurveyPolicy::emailHash($row['email'])) || !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) return 'contact-changed';
        if ($this->dueDate($row['bezoekdatum'], $settings['delayDays']) > $now->format('Y-m-d')) return 'scheduled';
        if ($snapshot['modules'] === []) return 'missing-modules';
        return $settings['enabled'] ? 'ready' : 'paused';
    }

    private function dueDate(string $date, int $days): string
    {
        return VisitSurveyPolicy::date($date)->modify('+' . $days . ' days')->format('Y-m-d');
    }

    /** Read-only queue preview; one row per visit, never weighted by students. */
    public function report(array $filters, DateTimeImmutable $now): array
    {
        $from = $filters['from'] ?? ''; $to = $filters['to'] ?? '';
        $sector = $filters['sector'] ?? 'all'; $variant = $filters['variant'] ?? 'all';
        if (!is_string($from) || !is_string($to) || !is_string($sector) || !is_string($variant)) throw new InvalidArgumentException('Ongeldig filter.');
        if ($from !== '') VisitSurveyPolicy::date($from);
        if ($to !== '') VisitSurveyPolicy::date($to);
        if ($from && $to && $from > $to) throw new InvalidArgumentException('De einddatum ligt voor de begindatum.');
        if (!in_array($sector, ['all','po','so','vo-lower','vo-upper','unknown'], true) || ($variant !== 'all' && !isset(BookingProgramVariant::LABELS[$variant]))) throw new InvalidArgumentException('Ongeldig filter.');
        $settings = $this->repository->settings();
        $rows = []; $distribution = array_fill_keys(array_keys(VisitSurveyPolicy::RATINGS), 0);
        $invited = 0; $responses = 0; $modules = []; $months = [];
        foreach ($this->repository->candidates() as $row) {
            $snapshot = $this->snapshot($row);
            if (($from && $snapshot['visitDate'] < $from) || ($to && $snapshot['visitDate'] > $to)
                || ($sector !== 'all' && $snapshot['sector'] !== $sector) || ($variant !== 'all' && $snapshot['variant'] !== $variant)) continue;
            if ($row['sent_at']) $invited++;
            if ($row['satisfaction'] !== null) {
                $responses++; $distribution[$row['satisfaction']]++;
                $month = substr($snapshot['visitDate'], 0, 7);
                $months[$month] ??= ['month' => $month, ...array_fill_keys(array_keys(VisitSurveyPolicy::RATINGS), 0)];
                $months[$month][$row['satisfaction']]++;
                foreach ($snapshot['modules'] as $module) {
                    $key = $module['key'];
                    $modules[$key] ??= ['key' => $key, 'label' => $module['label'], 'exposed' => 0, 'favorites' => 0];
                    $modules[$key]['exposed']++;
                    if ($key === $row['favorite_module']) $modules[$key]['favorites']++;
                }
            }
            $rows[] = ['bookingId' => (int) $row['id'], ...$snapshot,
                'state' => $this->state($row, $settings, $snapshot, $now), 'excluded' => (bool) $row['excluded'],
                'dueDate' => $this->dueDate($row['bezoekdatum'], $settings['delayDays']), 'sentAt' => $row['sent_at'],
                'satisfaction' => $row['satisfaction'], 'favoriteModule' => $row['favorite_module'], 'tip' => $row['tip'], 'answeredAt' => $row['answered_at']];
        }
        ksort($months);
        foreach ($modules as &$m) $m['percentage'] = round(100 * $m['favorites'] / $m['exposed'], 1);
        unset($m);
        usort($modules, static fn ($a, $b) => $b['percentage'] <=> $a['percentage']);
        return ['settings' => $settings, 'filters' => compact('from','to','sector','variant'),
            'invited' => $invited, 'responses' => $responses, 'responseRate' => $invited ? round(100 * $responses / $invited, 1) : null,
            'distribution' => $distribution, 'months' => array_values($months), 'modules' => array_values($modules), 'visits' => $rows];
    }

    /** Explicit caller-controlled sending. Production CLI is the only entry point wired to a real mailer. */
    public function dispatch(MailInterface $mailer, MailLayout $layout, string $baseUrl, DateTimeImmutable $now): array
    {
        $counts = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        if (!$this->repository->settings()['enabled']) return $counts;
        if (!preg_match('~^https://[^/]+(?:/[^?#]*)?$~', $baseUrl)) throw new \RuntimeException('Een vaste HTTPS-basis-URL is vereist.');
        foreach ($this->repository->candidates() as $candidate) {
            $id = (int) $candidate['id'];
            $result = $this->repository->withLock($id, function () use ($id, $mailer, $layout, $baseUrl, $now): string {
                // Refresh status/date/consent after acquiring the lock; a planner may have excluded the visit.
                $row = $this->repository->candidates($id)[0] ?? null;
                if ($row === null) return 'skipped';
                $snapshot = $this->snapshot($row);
                if ($this->state($row, $this->repository->settings(), $snapshot, $now) !== 'ready') return 'skipped';
                $token = bin2hex(random_bytes(32)); $time = $now->format('Y-m-d H:i:s');
                if (!$this->repository->claim($id, hash('sha256', $token), $snapshot, $time, $now->modify('+30 days')->format('Y-m-d H:i:s'))) return 'skipped';
                $url = rtrim($baseUrl, '/') . '/evaluatie/#token=' . $token;
                $template = new VisitSurveyMailTemplate($layout);
                $firstName = (string) $row['contactpersoon_voornaam'];
                $html = $template->html($firstName, $snapshot['visitDate'], $url);
                $text = $template->text($firstName, $snapshot['visitDate'], $url);
                try {
                    $mailer->send($row['email'], trim($row['contactpersoon_voornaam'] . ' ' . $row['contactpersoon_achternaam']), $template->subject(), $html, $text);
                    $this->repository->delivery($id, true, $time);
                    return 'sent';
                } catch (\Throwable $e) {
                    // SMTP failure can be ambiguous. Do not automatically retry and risk sending twice.
                    $this->repository->delivery($id, false, $time);
                    error_log('[VisitSurveyDelivery] booking=' . $id . ' exception=' . $e::class);
                    return 'failed';
                }
            });
            $counts[$result]++;
        }
        return $counts;
    }

    public function publicAction(string $token, string $action, array $input, DateTimeImmutable $now): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) throw new InvalidArgumentException('Deze evaluatielink is niet geldig of niet meer beschikbaar.');
        $hash = hash('sha256', $token); $row = $this->repository->invitation($hash);
        if (!$row) throw new InvalidArgumentException('Deze evaluatielink is niet geldig of niet meer beschikbaar.');
        return $this->repository->withLock((int) $row['booking_id'], function () use ($hash, $action, $input, $now): array {
            $row = $this->repository->invitation($hash);
            if (!$row || !in_array($action, ['open','respond','withdraw'], true)) throw new InvalidArgumentException('Deze evaluatie is niet beschikbaar.');
            // Withdrawal remains possible after expiry, without revealing the original answers.
            if ($action === 'withdraw') {
                $this->repository->withdraw((int) $row['booking_id'], $now->format('Y-m-d H:i:s'));
                return ['state' => 'withdrawn'];
            }
            if ($row['withdrawn_at']) return ['state' => 'withdrawn'];
            if ($row['expires_at'] < $now->format('Y-m-d H:i:s')) return ['state' => 'expired'];
            $snapshot = json_decode($row['snapshot_json'], true, 32, JSON_THROW_ON_ERROR);
            if ($row['excluded'] || $row['booking_status'] !== 'Definitief' || $row['bezoekdatum'] !== $snapshot['visitDate'] || !hash_equals($row['email_hash'], VisitSurveyPolicy::emailHash($row['email']))) return ['state' => 'unavailable'];
            if ($row['status'] === 'answered') return ['state' => 'answered'];
            if ($row['status'] !== 'sent') return ['state' => 'unavailable'];
            if ($action === 'respond') {
                $answer = VisitSurveyPolicy::answer($input, $snapshot['modules']);
                $this->repository->answer((int) $row['booking_id'], $answer, $now->format('Y-m-d H:i:s'));
                return ['state' => 'answered'];
            }
            return ['state' => 'open', 'visitDate' => $snapshot['visitDate'], 'modules' => $snapshot['modules']];
        });
    }
}
