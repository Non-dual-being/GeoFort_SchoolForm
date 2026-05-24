<?php
declare(strict_types=1);
namespace GeoFort\Services\Http;
use GeoFort\Validation\FormRules;

final class FormRulesHandler {

    public function __construct(
        private readonly JsonResponse $response,
    ) {}


    public function handle(): void {
        try {
            $rules = FormRules::getRulesForFrontend() ?? [];
            $this->response
            ->json([
                    'ok' => true,
                    'data' => $rules
                ])
            ->send();

        } catch (Throwable $e) {
            error_log(__METHOD__ . ' : ' . $e->getMessage());

            $this->response
                ->serverError(
                    'Validatie regels kunnen niet worden verzonden',
                    500,
                    false
                )
                ->send();
        }

    }
}