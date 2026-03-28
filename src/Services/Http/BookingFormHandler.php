<?php
declare(strict_types=1);
namespace GeoFort\Services\Http;

use GeoFort\Services\Booking\BookingRequestData;
use GeoFort\Services\Booking\BookingSubmissionService;

use GeoFort\Validation\FieldValidationException;
use GeoFort\Validation\Validator;
use GeoFort\Validation\FormRules;

final class BookingFormHandler
{
    public function __construct(
        private readonly JsonResponse $response,
        private readonly Validator $validator,
        private readonly BookingSubmissionService $submissionService,
        private readonly string $ip,
    ){}

    public function handle(array $postData): void 
    {
        
        try{

            $schoolnaam = $this->validator->text(
            'schoolnaam',
            $postData['schoolnaam'] ?? '',
            FormRules::RULES
        );

        $request = new BookingRequestData(
            schoolnaam: $schoolnaam
        );

        /**
         * todo: client ip toevoegen
         */
        $this->submissionService->submit($request, $ip);

        $this->response
            ->ok()
            ->send();

        } catch(FieldValidationException $e){
            $this->response
                ->validationError([
                    $e->getField() => $e->getMessage()
                ])
                ->send();
 
        } catch(\InvalidArgumentException $e){
            $this->response
            ->serverError($e->getMessage(), 500, true)
            ->send();
        } catch(\Throwable $e){
            $this->response
            ->serverError($e->getMessage(), 500, true)
            ->send();
        }

    }
}
?>