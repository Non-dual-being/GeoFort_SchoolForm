<?php
declare(strict_types=1);
namespace GeoFort\Services\Http\Api\Admin;
use JsonException;

final readonly class DashboardRosterJointWorkspaceRequest
{
    public function __construct(public array $expectedRevisions, public string $mode, public ?array $state, public array $options, public ?string $signature) {}

    public static function fromBody(string $body): self
    {
        if (strlen($body)>150000) throw new JsonException();
        $plans = DashboardRosterDayLessonsApplyRequest::fromBody($body);
        $payload=json_decode($body,true,16,JSON_THROW_ON_ERROR);
        $mode=$payload['mode']??null;
        if (!is_string($mode) || !in_array($mode,['read','preview','apply','optimize','candidates'],true)) throw new JsonException();
        $state=$payload['state']??null; $options=$payload['options']??[]; $signature=$payload['signature']??null;
        if (($state!==null && (!is_array($state) || !array_is_list($state))) || !is_array($options) || ($signature!==null && (!is_string($signature) || preg_match('/^[a-f0-9]{64}$/D',$signature)!==1))) throw new JsonException();
        foreach (['timePins','staffPins','retained','additions','approvedStaff'] as $key) if (isset($options[$key]) && (!is_array($options[$key]) || count($options[$key])>200)) throw new JsonException();
        if (isset($options['teamMode']) && !in_array($options['teamMode'],['existing','selection'],true)) throw new JsonException();
        if (isset($options['shiftLessons']) && !is_bool($options['shiftLessons'])) throw new JsonException();
        foreach (['teamIds','retained','releasedStaff'] as $key) if (isset($options[$key])) {
            if (!is_array($options[$key]) || !array_is_list($options[$key]) || count($options[$key])>200) throw new JsonException();
            foreach ($options[$key] as $id) if (!is_int($id) || $id<1) throw new JsonException();
        }
        if (isset($options['moduleSwaps']) && !is_bool($options['moduleSwaps'])) throw new JsonException();
        foreach ($options['approvedStaff']??[] as $approval) if (!is_array($approval) || !is_int($approval['id']??null) || $approval['id']<1 || !is_string($approval['schedule']??null) || strlen($approval['schedule'])>30000) throw new JsonException();
        foreach (['timePins','staffPins'] as $key) foreach ($options[$key]??[] as $pin) {
            if (!is_string($pin) || preg_match($key==='timePins'?'/^[1-9]\d*:[1-9]\d*$/D':'/^[1-9]\d*:[1-9]\d*:(?:[1-9]\d*|cook)$/D',$pin)!==1) throw new JsonException();
        }
        return new self($plans->expectedRevisions,$mode,$state,$options,$signature);
    }
}
