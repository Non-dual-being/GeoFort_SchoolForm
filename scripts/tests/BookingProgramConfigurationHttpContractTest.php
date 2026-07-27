<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
use GeoFort\Services\Http\Api\Admin\{BookingProgramConfigurationUpdateRequest,BookingProgramUpdateRequestException};
use GeoFort\Booking\ProgramConfiguration\{BookingProgramConfigurationCode,BookingProgramConfigurationResult,ProgramConfigurationIssueFactory};
use GeoFort\Services\Http\Api\Admin\BookingProgramConfigurationUpdateResponseMapper;
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$education='{"sector":"primairOnderwijs","selectedLevels":["regulier"],"selectedGroupsByLevel":{"regulier":["groep7"]}}';
$json='{"bookingId":181,"expected":{"status":"Definitief","visitDate":"2027-02-12","program":"ochtend","studentCount":80,"educationSelection":'.$education.',"choiceModule":null},"proposed":{"program":"dag","studentCount":120,"educationSelection":'.$education.',"choiceModule":"Earth-Watch"},"overrides":[]}';
$request=BookingProgramConfigurationUpdateRequest::fromJson($json);
$assert($request->bookingId===181&&$request->expected->status==='Definitief'&&$request->proposed->program==='dag'&&$request->proposed->visitDate===$request->expected->visitDate,'Volledige expected/proposed snapshot ontbreekt.');
foreach(['{}',str_replace('"bookingId":181','"bookingId":0',$json),substr($json,0,-1).',"adminId":4}',str_replace('"studentCount":120','"studentCount":"120"',$json)] as $invalid){try{BookingProgramConfigurationUpdateRequest::fromJson($invalid);throw new RuntimeException('Ongeldig request geaccepteerd.');}catch(BookingProgramUpdateRequestException $exception){$assert($exception->publicCode==='INVALID_REQUEST','Verkeerde requestcode.');}}
try{BookingProgramConfigurationUpdateRequest::fromJson(str_replace('"overrides":[]','"overrides":[{"ruleCode":"PROGRAM_WEEKDAY_MISMATCH","reason":"te kort"}]',$json));throw new RuntimeException('Korte overridereden geaccepteerd.');}catch(BookingProgramUpdateRequestException $exception){$assert($exception->publicCode==='INVALID_OVERRIDE_REQUEST','Verkeerde overridecode.');}
$root=dirname(__DIR__,2);$endpoint=file_get_contents($root.'/public/api/admin/requests/update-booking-program-configuration.php');$action=file_get_contents($root.'/src/Services/Http/Api/Admin/DashboardBookingProgramConfigurationUpdateAction.php');
$assert(str_contains($endpoint,'DashboardBookingProgramConfigurationUpdateAction')&&str_contains($action,"CSRF_SCOPE='update-booking-program-configuration'")&&str_contains($action,"\$_SESSION['user_id']"),'Afgebakend endpoint, CSRF of server-side admin ontbreekt.');
$mapper=new BookingProgramConfigurationUpdateResponseMapper();
foreach([
    ProgramConfigurationIssueFactory::create('LEVEL_SELECTION_LIMIT_EXCEEDED','educationSelection.selectedLevels',['sector'=>'voortgezetOnderbouw','maximum'=>3,'selected'=>4]),
    ProgramConfigurationIssueFactory::create('GROUP_SELECTION_LIMIT_EXCEEDED','educationSelection.selectedGroupsByLevel.havo',['sector'=>'voortgezetOnderbouw','level'=>'havo','maximum'=>3,'selected'=>4]),
    ProgramConfigurationIssueFactory::create('MISSING_CHOICE_MODULE','choiceModule'),
    ProgramConfigurationIssueFactory::create('INVALID_CHOICE_MODULE','choiceModule'),
] as $issue){$mapped=$mapper->map(new BookingProgramConfigurationResult(BookingProgramConfigurationCode::InvalidConfiguration,false,181,null,[$issue]));$payload=$mapped['payload']['validationIssues'][0];$assert($mapped['payload']['code']==='INVALID_PROGRAM_CONFIGURATION'&&$payload['title']!==''&&$payload['description']!=='','Specifiek issue mist resultcode, titel of beschrijving.');if(str_contains($issue->code,'LIMIT'))$assert(($payload['metadata']['maximum']??null)===3&&($payload['metadata']['selected']??null)===4,'Limietmetadata ontbreekt.');}
fwrite(STDOUT,"OK: programmaconfiguratie HTTP-contract geslaagd.\n");
