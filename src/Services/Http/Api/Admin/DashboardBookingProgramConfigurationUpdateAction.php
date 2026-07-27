<?php
declare(strict_types=1);
namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Booking\ProgramConfiguration\BookingProgramConfigurationCommand;
use GeoFort\Security\{AuthMiddleware,SessionGuard};
use GeoFort\Services\Auth\CsrfTokenService;
use GeoFort\Services\Booking\ProgramConfiguration\BookingProgramConfigurationService;
use GeoFort\Services\Http\Response\JsonResponse;
use Throwable;

final readonly class DashboardBookingProgramConfigurationUpdateAction
{
    public const CSRF_SCOPE='update-booking-program-configuration';
    public function __construct(private AuthMiddleware $auth,private SessionGuard $guard,private CsrfTokenService $csrf,private BookingProgramConfigurationService $service,private BookingProgramConfigurationUpdateResponseMapper $mapper,private JsonResponse $response){}
    public function send(string $method,string $contentType,?string $token,string $body,string $userAgent):void
    {
        $this->auth->startPublicSession();if(!$this->guard->validate($userAgent)){$this->auth->invalidateAuthentication();$this->error('UNAUTHENTICATED',401);return;}
        if($method!=='POST'){$this->response->json(['ok'=>false,'code'=>'METHOD_NOT_ALLOWED'],405)->header('Allow','POST')->send();return;}
        if(!str_starts_with(strtolower(trim($contentType)),'application/json')){$this->error('INVALID_REQUEST',422);return;}
        if(!$this->csrf->validate(self::CSRF_SCOPE,$token)){$this->error('INVALID_CSRF',403);return;}
        try{$request=BookingProgramConfigurationUpdateRequest::fromJson($body);}catch(BookingProgramUpdateRequestException $exception){$this->error($exception->publicCode,422);return;}
        try{$result=$this->service->change(new BookingProgramConfigurationCommand($request->bookingId,$request->expected,$request->proposed,(int)$_SESSION['user_id'],$request->overrides));$mapped=$this->mapper->map($result);$this->response->json($mapped['payload'],$mapped['status'])->send();}
        catch(Throwable){error_log('[DashboardBookingProgramConfigurationUpdateAction] Wijziging mislukt.');$this->error('DATABASE_ERROR',500);}
    }
    private function error(string $code,int $status):void{$this->response->json(['ok'=>false,'code'=>$code],$status)->send();}
}
