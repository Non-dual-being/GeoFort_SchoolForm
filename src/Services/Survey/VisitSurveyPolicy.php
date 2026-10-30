<?php
declare(strict_types=1);
namespace GeoFort\Services\Survey;

use DateTimeImmutable;
use InvalidArgumentException;

final class VisitSurveyPolicy
{
    public const CONSENT_VERSION = 'visit-evaluation-2026-10-v1';
    public const RATINGS = ['dissatisfied' => 'Ontevreden', 'neutral' => 'Neutraal', 'satisfied' => 'Tevreden'];

    public static function consent(mixed $value): bool
    {
        if (in_array($value, [null, false, '0', 0, ''], true)) return false;
        if (in_array($value, [true, '1', 1], true)) return true;
        throw new \GeoFort\Validation\FieldValidationException('evaluationConsent', 'Kies zelf of je een bezoekevaluatie wilt ontvangen.');
    }

    public static function emailHash(string $email): string { return hash('sha256', mb_strtolower(trim($email))); }

    /** Keep the same Unicode definition in resources/js/survey/policy.ts. */
    public static function wordCount(string $value): int
    {
        return preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\p{M}]*(?:[\x{2019}\x{0027}\x{002D}][\p{L}\p{N}][\p{L}\p{N}\p{M}]*)*/u', $value);
    }

    public static function answer(array $input, array $modules): array
    {
        $rating = $input['satisfaction'] ?? null;
        $favorite = $input['favoriteModule'] ?? null;
        $tip = $input['tip'] ?? '';
        if (!is_string($rating) || !isset(self::RATINGS[$rating])) throw new InvalidArgumentException('Kies hoe jullie het bezoek hebben ervaren.');
        if ($favorite !== null && (!is_string($favorite) || !in_array($favorite, array_column($modules, 'key'), true))) {
            throw new InvalidArgumentException('Kies een module uit jullie bezoek of Geen voorkeur.');
        }
        if (!is_string($tip) || !mb_check_encoding($tip, 'UTF-8')) throw new InvalidArgumentException('Vul een geldige tip in.');
        $tip = trim(str_replace(["\r\n", "\r"], "\n", $tip));
        if (mb_strlen($tip) > 1000 || self::wordCount($tip) > 50 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $tip)) {
            throw new InvalidArgumentException('Gebruik maximaal 50 woorden en 1.000 tekens voor je tip.');
        }
        return ['satisfaction' => $rating, 'favoriteModule' => $favorite, 'tip' => $tip];
    }

    public static function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('Europe/Amsterdam'));
        if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException('Kies een geldige datum.');
        return $date;
    }
}
