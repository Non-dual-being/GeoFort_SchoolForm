<?php
declare(strict_types=1);
namespace GeoFort\Services\Http\Api\Admin;
use GeoFort\Security\AuthMiddleware;
use GeoFort\Security\SessionGuard;
use GeoFort\Services\Auth\CsrfTokenService;
use GeoFort\Services\Booking\Pricing\LegacyBookingPriceAcceptanceService;
use GeoFort\Services\Http\Response\JsonResponse;
use JsonException;
use Throwable;

final readonly class DashboardLegacyBookingPriceAcceptanceAction
{
    public const CSRF_SCOPE='accept-legacy-booking-price';
    public function __construct(private AuthMiddleware $auth,private SessionGuard $guard,private CsrfTokenService $csrf,private LegacyBookingPriceAcceptanceService $service,private JsonResponse $response){}
    public function send(string $method,string $contentType,?string $token,string $body,string $userAgent):void
    {
        $this->auth->startPublicSession();
        if(!$this->guard->validate($userAgent)){$this->auth->invalidateAuthentication();$this->error('UNAUTHENTICATED',401);return;}
        if($method!=='POST'){$this->response->json(['ok'=>false,'code'=>'METHOD_NOT_ALLOWED'],405)->header('Allow','POST')->send();return;}
        if(!str_starts_with(strtolower(trim($contentType)),'application/json')){$this->error('INVALID_REQUEST',422);return;}
        if(!$this->csrf->validate(self::CSRF_SCOPE,$token)){$this->error('INVALID_CSRF',403);return;}
        try{$payload=json_decode($body,true,512,JSON_THROW_ON_ERROR);}catch(JsonException){$this->error('MALFORMED_JSON',422);return;}
        if(!is_array($payload)||!is_int($payload['bookingId']??null)||($payload['bookingId']??0)<1||($payload['explicitlyAccepted']??null)!==true){$this->error('LEGACY_PRICE_ACCEPTANCE_REQUIRED',422);return;}
        try{
            $snapshot=$this->service->accept($payload['bookingId'],(int)$_SESSION['user_id'],true);
            $this->response->json(['ok'=>true,'code'=>'SUCCESS','snapshot'=>['calculationState'=>$snapshot->calculationState,'pricingVersion'=>$snapshot->pricingVersion,'currencyCode'=>$snapshot->currencyCode,'visitAmountInclVatCents'=>$snapshot->visitAmountInclVatCents,'cateringAmountInclVatCents'=>$snapshot->cateringAmountInclVatCents,'totalAmountInclVatCents'=>$snapshot->totalAmountInclVatCents,'totalAmountExclVatCents'=>$snapshot->totalAmountExclVatCents,'vatAmountCents'=>$snapshot->vatAmountCents]],200)->send();
        }catch(Throwable $exception){$code=in_array($exception->getMessage(),['BOOKING_NOT_FOUND','PRICE_SNAPSHOT_CONFLICT','PRICE_CALCULATION_INVALID','LEGACY_PRICE_ACCEPTANCE_NOT_ALLOWED'],true)?$exception->getMessage():'DATABASE_ERROR';$status=$code==='BOOKING_NOT_FOUND'?404:($code==='DATABASE_ERROR'?500:409);if($status===500)error_log(sprintf('Legacy price acceptance failure: exception=%s booking=%d',$exception::class,(int)($payload['bookingId']??0)));$this->error($code,$status);}
    }
    private function error(string $code,int $status):void{$this->response->json(['ok'=>false,'code'=>$code],$status)->send();}
}
