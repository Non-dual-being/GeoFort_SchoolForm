<?php
declare(strict_types=1);
namespace GeoFort\Booking\Validation;

use DateTimeImmutable;
use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Booking\Stored\StoredBooking;
use GeoFort\Validation\ChoiceModuleSelectionValidator;
use GeoFort\Validation\EducationSelectionValidator;
use GeoFort\Validation\FieldValidationException;
use GeoFort\Validation\ProgramSelectionValidator;

final readonly class StoredBookingProgramValidator
{
    public function __construct(
        private ProgramSelectionValidator $programValidator=new ProgramSelectionValidator(),
        private EducationSelectionValidator $educationValidator=new EducationSelectionValidator(),
        private ChoiceModuleSelectionValidator $moduleValidator=new ChoiceModuleSelectionValidator(),
        private StoredBookingStudentCountValidator $studentValidator=new StoredBookingStudentCountValidator(),
    ) {}

    public function validateChange(StoredBooking $previous, StoredBooking $proposed): StoredBookingValidationResult
    {
        $previousIssues = $this->validate($previous)->issues;
        $previousKeys = [];
        foreach ($previousIssues as $issue) {
            if ($issue->category !== StoredBookingIssueCategory::Structural) {
                $previousKeys[$issue->code . "\0" . $issue->field] = true;
            }
        }

        return new StoredBookingValidationResult(array_values(array_filter(
            $this->validate($proposed)->issues,
            static fn (StoredBookingIssue $issue): bool =>
                $issue->category === StoredBookingIssueCategory::Structural
                || !isset($previousKeys[$issue->code . "\0" . $issue->field]),
        )));
    }

    public function validate(StoredBooking $booking):StoredBookingValidationResult
    {
        if(!BookingProgramConfig::programExists($booking->program)){
            return new StoredBookingValidationResult([new StoredBookingIssue('INVALID_CONFIGURATION_KEY',StoredBookingIssueCategory::Structural,'programma')]);
        }
        if(!BookingProgramConfig::isValidSchoolSectorValue($booking->schoolSector)){
            return new StoredBookingValidationResult([new StoredBookingIssue('INVALID_CONFIGURATION_KEY',StoredBookingIssueCategory::Structural,'onderwijsSector')]);
        }
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$booking->visitDate);
        if($date===false||$date->format('Y-m-d')!==$booking->visitDate){
            return new StoredBookingValidationResult([new StoredBookingIssue('INVALID_VISIT_DATE',StoredBookingIssueCategory::Structural,'bezoekdatum')]);
        }
        $issues=[];
        try{$this->programValidator->validate($booking->program,$booking->schoolSector,$date);}
        catch(FieldValidationException){$issues[]=new StoredBookingIssue('CURRENT_CONFIGURATION_MISMATCH',StoredBookingIssueCategory::Policy,'programma');}
        catch(\InvalidArgumentException|\LogicException){$issues[]=new StoredBookingIssue('INVALID_CONFIGURATION_KEY',StoredBookingIssueCategory::Structural,'configuratie');}
        try{
            $education=$this->educationValidator->validate($booking->educationSelection->toJson(),$booking->schoolSector);
            $this->studentValidator->validate($booking->studentCount,$booking->schoolSector,$booking->program);
            $this->moduleValidator->validate($booking->choiceModuleKey,$booking->schoolSector,$booking->program,$education);
        }catch(FieldValidationException $exception){
            $issues[]=new StoredBookingIssue('CURRENT_CONFIGURATION_MISMATCH',$booking->source->isLegacy()?StoredBookingIssueCategory::HistoricalConfiguration:StoredBookingIssueCategory::Policy,$exception->getField());
        }catch(\InvalidArgumentException|\LogicException){
            $issues[]=new StoredBookingIssue('INVALID_CONFIGURATION_KEY',StoredBookingIssueCategory::Structural,'configuratie');
        }
        return new StoredBookingValidationResult($issues);
    }
}
