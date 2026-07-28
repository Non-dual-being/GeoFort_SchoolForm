<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\SchoolContact;

use GeoFort\Booking\SchoolContact\BookingSchoolContactChangeCode;
use GeoFort\Booking\SchoolContact\BookingSchoolContactChangeCommand;
use GeoFort\Booking\SchoolContact\BookingSchoolContactChangeResult;
use GeoFort\Booking\SchoolContact\BookingSchoolContactDetails;
use GeoFort\Services\Sql\BookingChangeHistorySqlRepository;
use GeoFort\Services\Sql\BookingSchoolContactSqlRepository;
use GeoFort\Services\Sql\StoredBookingSqlRepository;
use PDO;
use RuntimeException;
use Throwable;

final readonly class BookingSchoolContactChangeService
{
    public function __construct(private PDO $pdo,private StoredBookingSqlRepository $bookings,private BookingSchoolContactSqlRepository $repository,private BookingChangeHistorySqlRepository $history,private BookingSchoolContactValidator $validator) {}

    public function change(BookingSchoolContactChangeCommand $command): BookingSchoolContactChangeResult
    {
        $previous=null;
        try {
            if(!$this->pdo->beginTransaction())throw new RuntimeException('Transactie kon niet worden gestart.');
            $booking=$this->bookings->findByIdForUpdate($command->bookingId);
            if($booking===null)return $this->rollback($command,BookingSchoolContactChangeCode::BookingNotFound);
            $previous=BookingSchoolContactDetails::fromStoredBooking($booking);
            if($previous!=$command->expected)return $this->rollback($command,BookingSchoolContactChangeCode::Conflict,$previous);
            $validated=$this->validator->validate($command->proposed);
            if($validated['details']===null)return $this->rollback($command,BookingSchoolContactChangeCode::InvalidDetails,$previous,$validated['issues']);
            $current=$validated['details'];$changed=$this->changedFields($previous,$current);
            if($changed===[])return $this->rollback($command,BookingSchoolContactChangeCode::NoChange,$previous,[], $current);
            if(!$this->repository->guardedUpdate($booking->id,$command->expected,$current))return $this->rollback($command,BookingSchoolContactChangeCode::Conflict,$previous);
            $historyId=$this->history->insertSchoolContactChange($booking->id,$changed,$command->actingAdminId);
            if(!$this->pdo->commit())throw new RuntimeException('Transactie kon niet worden vastgelegd.');
            return new BookingSchoolContactChangeResult(BookingSchoolContactChangeCode::Success,true,$booking->id,$previous,$current,[],$changed,$historyId);
        } catch(Throwable $exception) {
            error_log(sprintf('School/contact change failure: exception=%s booking=%d',$exception::class,$command->bookingId));
            $this->rollbackActive();
            return new BookingSchoolContactChangeResult(BookingSchoolContactChangeCode::DatabaseError,false,$command->bookingId,$previous,$previous);
        }
    }

    /** @return array<string,array{before:string,after:string}> */
    private function changedFields(BookingSchoolContactDetails $before,BookingSchoolContactDetails $after):array
    {
        $map=['schoolnaam'=>'schoolName','land'=>'country','adres'=>'address','postcode'=>'postalCode','plaats'=>'city','school_telefoonnummer'=>'schoolPhone','contactpersoon_voornaam'=>'contactFirstName','contactpersoon_achternaam'=>'contactLastName','email'=>'contactEmail','contactpersoon_telefoonnummer'=>'contactPhone'];$changed=[];
        foreach($map as $column=>$property)if($before->{$property}!==$after->{$property})$changed[$column]=['before'=>$before->{$property},'after'=>$after->{$property}];
        return $changed;
    }
    private function rollback(BookingSchoolContactChangeCommand $command,BookingSchoolContactChangeCode $code,?BookingSchoolContactDetails $previous=null,array $issues=[],?BookingSchoolContactDetails $current=null):BookingSchoolContactChangeResult{$this->rollbackActive();return new BookingSchoolContactChangeResult($code,$code===BookingSchoolContactChangeCode::NoChange,$command->bookingId,$previous,$current??$previous,$issues);}
    private function rollbackActive():void{if($this->pdo->inTransaction())try{$this->pdo->rollBack();}catch(Throwable){}}
}
