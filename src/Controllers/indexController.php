<?php
declare(strict_types=1);
namespace GeoFort\Controllers;
use GeoFort\Services\ViteService;

final class indexController {
    public function __construct(private ViteService $viteService){}

    public function render(){
        $vite = $this->viteService;
        require TEMPLATE_PATH . '/app.php';
    }
}
?>