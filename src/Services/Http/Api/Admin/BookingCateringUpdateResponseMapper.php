<?php

declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Services\Booking\Catering\BookingCateringChangeCode;
use GeoFort\Services\Booking\Catering\BookingCateringChangeResult;

final class BookingCateringUpdateResponseMapper
{
    /** @return array{status:int,payload:array<string,mixed>} */
    public function map(BookingCateringChangeResult $result): array
    {
        return ['status'=>$this->status($result->code),'payload'=>[
            'ok'=>$result->success,'code'=>$result->code->value,'bookingId'=>$result->bookingId,
            'status'=>$result->status,'previous'=>$result->previous?->toArray(),'current'=>$result->current?->toArray(),
            'changedFields'=>$result->changedFields,
            'validationIssues'=>array_map(static fn($issue)=>['code'=>$issue->code,'category'=>$issue->category->value,'field'=>$issue->field,'severity'=>$issue->severity->value,'overridable'=>$issue->overridable,'title'=>$issue->title,'description'=>$issue->description,'metadata'=>$issue->metadata],$result->validationIssues),
            'changeHistoryId'=>$result->changeHistoryId,
        ]];
    }
    public function status(BookingCateringChangeCode $code): int
    {
        return match($code) {
            BookingCateringChangeCode::Success, BookingCateringChangeCode::NoCateringChange => 200,
            BookingCateringChangeCode::BookingNotFound => 404,
            BookingCateringChangeCode::CateringConflict => 409,
            BookingCateringChangeCode::InvalidRequest, BookingCateringChangeCode::InvalidCateringSelection, BookingCateringChangeCode::InvalidStoredBooking => 422,
            BookingCateringChangeCode::DatabaseError => 500,
        };
    }
}
