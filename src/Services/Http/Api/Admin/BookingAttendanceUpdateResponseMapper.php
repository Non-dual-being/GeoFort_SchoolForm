<?php
declare(strict_types=1);
namespace GeoFort\Services\Http\Api\Admin;
use GeoFort\Booking\Attendance\{BookingAttendanceChangeCode,BookingAttendanceChangeResult};
final class BookingAttendanceUpdateResponseMapper
{
    /** @return array{status:int,payload:array<string,mixed>} */
    public function map(BookingAttendanceChangeResult $r):array{return ['status'=>$this->status($r->code),'payload'=>['ok'=>$r->success,'code'=>$r->code->value,'bookingId'=>$r->bookingId,'previousStudentCount'=>$r->previousStudentCount,'currentStudentCount'=>$r->currentStudentCount,'previousSupervisorCount'=>$r->previousSupervisorCount,'currentSupervisorCount'=>$r->currentSupervisorCount,'validationIssues'=>array_map(static fn($i)=>['code'=>$i->code,'category'=>$i->category->value,'field'=>$i->field,'severity'=>$i->severity->value,'overridable'=>$i->overridable,'title'=>$i->title,'description'=>$i->description,'metadata'=>$i->metadata],$r->validationIssues),'capacity'=>$r->capacity===null?null:(array)$r->capacity,'overriddenRules'=>array_map(static fn($o)=>['ruleCode'=>$o->ruleCode],$r->overriddenRules),'overrideCount'=>count($r->overriddenRules),'changeHistoryId'=>$r->changeHistoryId]];}
    public function status(BookingAttendanceChangeCode $code):int{return match($code){BookingAttendanceChangeCode::Success=>200,BookingAttendanceChangeCode::BookingNotFound=>404,BookingAttendanceChangeCode::AttendanceConflict,BookingAttendanceChangeCode::OverrideRequired=>409,BookingAttendanceChangeCode::NoChanges,BookingAttendanceChangeCode::InvalidRequest,BookingAttendanceChangeCode::InvalidStoredBooking,BookingAttendanceChangeCode::InvalidOverrideRequest,BookingAttendanceChangeCode::OverrideNotAllowed=>422,BookingAttendanceChangeCode::OverridePermissionDenied=>403,BookingAttendanceChangeCode::DatabaseError=>500};}
}
