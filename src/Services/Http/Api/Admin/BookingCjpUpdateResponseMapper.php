<?php

declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Booking\Cjp\BookingCjpChangeCode;
use GeoFort\Booking\Cjp\BookingCjpChangeResult;

final class BookingCjpUpdateResponseMapper
{
    /** @return array{status:int,payload:array<string,mixed>} */
    public function map(BookingCjpChangeResult $result): array
    {
        return ['status'=>$this->status($result->code), 'payload'=>[
            'ok'=>$result->success, 'code'=>$result->code->value, 'bookingId'=>$result->bookingId,
            'previous'=>$result->previous?->toArray(), 'current'=>$result->current?->toArray(),
            'changedFields'=>$result->changedFields,
            'validationIssues'=>array_map(static fn($issue)=>[
                'code'=>$issue->code, 'field'=>$issue->field, 'category'=>$issue->category,
                'severity'=>$issue->severity, 'title'=>$issue->title,
                'description'=>$issue->description, 'metadata'=>$issue->metadata,
            ], $result->issues),
            'changeHistoryId'=>$result->changeHistoryId,
        ]];
    }

    private function status(BookingCjpChangeCode $code): int
    {
        return match ($code) {
            BookingCjpChangeCode::Success, BookingCjpChangeCode::NoChange => 200,
            BookingCjpChangeCode::BookingNotFound => 404,
            BookingCjpChangeCode::Conflict => 409,
            BookingCjpChangeCode::InvalidDetails => 422,
            BookingCjpChangeCode::DatabaseError => 500,
        };
    }
}
