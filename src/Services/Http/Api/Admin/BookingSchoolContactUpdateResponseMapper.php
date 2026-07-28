<?php

declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Booking\SchoolContact\BookingSchoolContactChangeCode;
use GeoFort\Booking\SchoolContact\BookingSchoolContactChangeResult;

final class BookingSchoolContactUpdateResponseMapper
{
    /** @return array{status:int,payload:array<string,mixed>} */
    public function map(BookingSchoolContactChangeResult $result):array{return ['status'=>$this->status($result->code),'payload'=>['ok'=>$result->success,'code'=>$result->code->value,'bookingId'=>$result->bookingId,'previous'=>$result->previous?->toArray(),'current'=>$result->current?->toArray(),'changedFields'=>$result->changedFields,'validationIssues'=>array_map(static fn($issue)=>['code'=>$issue->code,'field'=>$issue->field,'category'=>$issue->category,'severity'=>$issue->severity,'title'=>$issue->title,'description'=>$issue->description,'metadata'=>$issue->metadata],$result->issues),'changeHistoryId'=>$result->changeHistoryId]];}
    public function status(BookingSchoolContactChangeCode $code):int{return match($code){BookingSchoolContactChangeCode::Success,BookingSchoolContactChangeCode::NoChange=>200,BookingSchoolContactChangeCode::BookingNotFound=>404,BookingSchoolContactChangeCode::Conflict=>409,BookingSchoolContactChangeCode::InvalidRequest,BookingSchoolContactChangeCode::InvalidDetails=>422,BookingSchoolContactChangeCode::DatabaseError=>500};}
}
