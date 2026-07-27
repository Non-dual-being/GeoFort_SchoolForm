<?php
declare(strict_types=1);
namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Booking\ProgramConfiguration\{BookingProgramConfigurationCode,BookingProgramConfigurationResult};

final class BookingProgramConfigurationUpdateResponseMapper
{
    /** @return array{status:int,payload:array<string,mixed>} */
    public function map(BookingProgramConfigurationResult $result):array
    {
        return ['status'=>$this->status($result->code),'payload'=>[
            'ok'=>$result->success,'code'=>$result->code->value,'bookingId'=>$result->bookingId,
            'current'=>$result->current===null?null:[
                'status'=>$result->current->status,'visitDate'=>$result->current->visitDate,'program'=>$result->current->program,
                'studentCount'=>$result->current->studentCount,'educationSelection'=>$result->current->educationSelection->toArray(),'choiceModule'=>$result->current->choiceModule,
            ],
            'validationIssues'=>array_map(static fn($i)=>['code'=>$i->code,'category'=>$i->category->value,'field'=>$i->field,'severity'=>$i->severity->value,'overridable'=>$i->overridable,'title'=>$i->title,'description'=>$i->description,'metadata'=>$i->metadata],$result->issues),
            'overriddenRules'=>array_map(static fn($o)=>['ruleCode'=>$o->ruleCode],$result->overrides),
            'overrideCount'=>count($result->overrides),'changeHistoryId'=>$result->changeHistoryId,
        ]];
    }
    private function status(BookingProgramConfigurationCode $code):int{return match($code){
        BookingProgramConfigurationCode::Success,BookingProgramConfigurationCode::NoChange=>200,
        BookingProgramConfigurationCode::NotFound=>404,
        BookingProgramConfigurationCode::Conflict,BookingProgramConfigurationCode::OverrideRequired=>409,
        BookingProgramConfigurationCode::OverridePermissionDenied=>403,
        BookingProgramConfigurationCode::DatabaseError=>500,
        default=>422,
    };}
}
