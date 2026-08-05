<?php
declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Services\Dashboard\Booking\Revenue\BookingRevenueCriteriaFactory;
use GeoFort\Services\Dashboard\Booking\Revenue\BookingRevenueCsvWriter;
use GeoFort\Services\Dashboard\Booking\Revenue\BookingRevenueReportService;
use GeoFort\Services\Http\PrivatePageBootstrapper;
use GeoFort\Services\Http\Response\JsonResponse;
use GeoFort\Validation\FieldValidationException;
use Throwable;

final readonly class DashboardBookingRevenueCsvExportAction
{
    public function __construct(private PrivatePageBootstrapper $auth, private BookingRevenueCriteriaFactory $factory, private BookingRevenueReportService $service, private BookingRevenueCsvWriter $writer, private JsonResponse $response) {}
    /** @param array<string, mixed> $query */
    public function send(string $method, array $query): void
    {
        try {
            $this->auth->init();
            if ($method !== 'GET') { $this->response->methodNotAllowed()->header('Allow','GET')->send(); return; }
            $bounds=$this->service->availableVisitDateRange();
            if ($bounds->isEmpty()) { $this->response->validationError(['export'=>'Er zijn geen boekingen om te exporteren.'])->send(); return; }
            $criteria=$this->factory->create($query,$bounds); $rows=$this->service->exportRows($criteria);
            if ($rows===[]) { $this->response->validationError(['export'=>'Er zijn geen boekingen om te exporteren.'])->send(); return; }
            $scope=match($criteria->scope){'option'=>'in-optie','combined'=>'prognose',default=>'definitief'};
            $prefix=$criteria->scope==='combined'?'omzetprognose':'omzet-'.$scope;
            $filename=sprintf('%s-%s-tot-%s.csv',$prefix,$this->date($criteria->startDate),$this->date($criteria->endDate));
            header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="'.$filename.'"'); header('Cache-Control: no-store, private'); header('X-Content-Type-Options: nosniff');
            $stream=fopen('php://output','wb'); if($stream===false) throw new \RuntimeException('CSV-uitvoer kon niet worden geopend.'); $this->writer->write($stream,$rows); fclose($stream);
        } catch(FieldValidationException $e){$this->response->validationError([$e->getField()=>$e->getMessage()])->send();}
        catch(Throwable $e){error_log('[DashboardBookingRevenueCsvExportAction] failed: '.$e::class);if(!headers_sent())$this->response->serverError('Exporteren is niet gelukt.',500,false)->send();}
    }
    private function date(string $date):string{return implode('-',array_reverse(explode('-',$date)));}
}
