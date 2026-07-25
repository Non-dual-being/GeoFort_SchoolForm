<?php
declare(strict_types=1);
namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Booking\VisitDate\{BookingVisitDateChangeCode,BookingVisitDateChangeResult};

final class BookingVisitDateUpdateResponseMapper
{
    /** @return array{status:int,payload:array<string,mixed>} */
    public function map(BookingVisitDateChangeResult $r):array{return ['status'=>$this->status($r->code),'payload'=>['ok'=>$r->success,'code'=>$r->code->value,'bookingId'=>$r->bookingId,'previousVisitDate'=>$r->previousVisitDate,'currentVisitDate'=>$r->currentVisitDate,'validationIssues'=>array_map(static fn($i)=>['code'=>$i->code,'category'=>$i->category->value,'field'=>$i->field,'severity'=>$i->severity->value,'overridable'=>$i->overridable,'title'=>$i->title,'description'=>$i->description,'metadata'=>$i->metadata],$r->validationIssues),'overriddenRules'=>array_map(static fn($o)=>['ruleCode'=>$o->ruleCode],$r->overriddenRules),'overrideCount'=>count($r->overriddenRules),'changeHistoryId'=>$r->changeHistoryId]];}
    public function status(BookingVisitDateChangeCode $code):int{return match($code){BookingVisitDateChangeCode::Success,BookingVisitDateChangeCode::NoVisitDateChange=>200,BookingVisitDateChangeCode::BookingNotFound=>404,BookingVisitDateChangeCode::VisitDateConflict,BookingVisitDateChangeCode::OverrideRequired=>409,BookingVisitDateChangeCode::InvalidRequest,BookingVisitDateChangeCode::InvalidVisitDate,BookingVisitDateChangeCode::InvalidStoredBooking,BookingVisitDateChangeCode::InvalidOverrideRequest,BookingVisitDateChangeCode::OverrideNotAllowed=>422,BookingVisitDateChangeCode::OverridePermissionDenied=>403,BookingVisitDateChangeCode::DatabaseError=>500};}
}
