<?php
declare(strict_types=1);
namespace GeoFort\Services\Http\Api\Admin;

use JsonException;

final readonly class DashboardRosterPairRequest
{
    public function __construct(public array $revisions, public string $workflow, public ?int $pairId, public ?int $pairRevision, public bool $confirmRestart = false) {}

    public static function fromBody(string $body, bool $archive): self
    {
        $p=json_decode($body,true,16,JSON_THROW_ON_ERROR);
        $revisions=DashboardRosterDayLessonsApplyRequest::fromBody($body)->expectedRevisions;
        if (count($revisions)!==2) throw new JsonException();
        if ($archive) {
            if (($p['confirmArchiveBoth']??null)!==true || !is_int($p['pairId']??null) || $p['pairId']<1
                || !is_int($p['pairRevision']??null) || $p['pairRevision']<1) throw new JsonException();
        } elseif (!in_array($p['workflow']??null,['lessons_first','school_first'],true)) throw new JsonException();
        if (!$archive && ($p['confirmRestart']??null)!==true) throw new JsonException();
        return new self($revisions,$archive?'lessons_first':$p['workflow'],$archive?$p['pairId']:null,$archive?$p['pairRevision']:null,!$archive);
    }
}
