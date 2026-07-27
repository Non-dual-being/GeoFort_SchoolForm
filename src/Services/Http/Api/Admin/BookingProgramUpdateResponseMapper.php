<?php
declare(strict_types=1);
namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Booking\Program\{BookingProgramChangeCode,BookingProgramChangeResult};

final class BookingProgramUpdateResponseMapper
{
    /** @return array{status:int,payload:array<string,mixed>} */
    public function map(BookingProgramChangeResult $r):array{return ['status'=>$this->status($r->code),'payload'=>['ok'=>$r->success,'code'=>$r->code->value,'bookingId'=>$r->bookingId,'previousProgram'=>$r->previousProgram,'currentProgram'=>$r->currentProgram,'validationIssues'=>array_map(static fn($i)=>['code'=>$i->code,'category'=>$i->category->value,'field'=>$i->field,'severity'=>$i->severity->value,'overridable'=>$i->overridable,'title'=>$i->title,'description'=>$i->description,'metadata'=>$i->metadata],$r->validationIssues),'overriddenRules'=>array_map(static fn($o)=>['ruleCode'=>$o->ruleCode],$r->overriddenRules),'overrideCount'=>count($r->overriddenRules),'changeHistoryId'=>$r->changeHistoryId]];}
    public function status(BookingProgramChangeCode $code):int{return match($code){BookingProgramChangeCode::Success,BookingProgramChangeCode::NoProgramChange=>200,BookingProgramChangeCode::BookingNotFound=>404,BookingProgramChangeCode::ProgramConflict,BookingProgramChangeCode::OverrideRequired=>409,BookingProgramChangeCode::InvalidRequest,BookingProgramChangeCode::InvalidProgramSelection,BookingProgramChangeCode::InvalidStoredBooking,BookingProgramChangeCode::InvalidOverrideRequest,BookingProgramChangeCode::OverrideNotAllowed=>422,BookingProgramChangeCode::OverridePermissionDenied=>403,BookingProgramChangeCode::DatabaseError=>500};}
}
