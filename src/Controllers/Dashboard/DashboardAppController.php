<?php
declare(strict_types=1);

namespace GeoFort\Controllers\Dashboard;

use GeoFort\Services\Dashboard\DashboardBootstrapService;
use GeoFort\Services\Http\PrivatePageBootstrapper;
use GeoFort\Services\ViteService;

final class DashboardAppController
{
    //property promotion
    public function __construct(
        private readonly PrivatePageBootstrapper $privatePageBootstrapper,
        private readonly DashboardBootstrapService $dashboardBootstrapService,
        private readonly ViteService $viteService,
    ) {}

    /**
     * dashboard bootstrap -> gather dasboard data
     * private bootstrapper quarded session request
     * Vite serve enables css and javascript files
     */

    public function render(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            header('Allow: GET');
            http_response_code(405);
            return;
        }

        header('Cache-Control: no-store, private'); //only enduser uses the info on the dashboard
        header('Pragma: no-cache'); 

        $this->privatePageBootstrapper->init();
        $bootstrapData = $this->dashboardBootstrapService->build();
        $bootstrapJson = json_encode(
            $bootstrapData->toArray(),
            JSON_THROW_ON_ERROR
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT,
        );
        $vite = $this->viteService;

        require TEMPLATE_PATH . '/dashboard/app.php';
    }
}
