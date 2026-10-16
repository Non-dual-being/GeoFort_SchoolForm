<?php
declare(strict_types=1);
namespace GeoFort\Services\Http\Api\Admin;
use GeoFort\Security\{AuthMiddleware,SessionGuard};
use GeoFort\Services\Dashboard\Roster\{StaffDeploymentService,StaffDeploymentXlsxWriter};
use GeoFort\Services\Http\Response\JsonResponse;
use InvalidArgumentException;
use Throwable;
final readonly class DashboardStaffDeploymentAction
{
    public function __construct(private AuthMiddleware $auth,private SessionGuard $guard,private StaffDeploymentService $service,private JsonResponse $response) {}
    public function send(string $method,array $query,string $userAgent):void
    {
        $this->auth->startPublicSession();
        if(!$this->guard->validate($userAgent)){$this->auth->invalidateAuthentication();$this->response->json(['ok'=>false,'code'=>'UNAUTHENTICATED'],401)->send();return;}
        if($method!=='GET'){$this->response->methodNotAllowed()->header('Allow','GET')->send();return;}
        header('Cache-Control: no-store');
        try {
            $report=$this->service->report($query);
            if(($query['format']??'json')==='xlsx'){
                $file=tempnam(sys_get_temp_dir(),'staff-xlsx-');
                if($file===false)throw new \RuntimeException('Tijdelijk bestand ontbreekt.');
                try{(new StaffDeploymentXlsxWriter())->write($file,$report);header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header('Content-Disposition: attachment; filename="personeelsinzet-'.$report['filters']['from'].'-'.$report['filters']['to'].'.xlsx"');readfile($file);}finally{unlink($file);}
            }else{$this->response->json(['ok'=>true,'data'=>$report])->send();}
        }catch(InvalidArgumentException $e){$this->response->json(['ok'=>false,'message'=>$e->getMessage()],422)->send();}
        catch(Throwable $e){error_log('[StaffDeployment] '.$e::class);$this->response->serverError('Personeelsinzet kon niet worden geladen.',500,false)->send();}
    }
}
