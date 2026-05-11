<?php
declare(strict_types=1);
namespace GeoFort\Controllers;
use GeoFort\Services\ViteService;
use GeoFort\Validation\FormRules;

final class indexController {
    public function __construct(private ViteService $viteService){}

    public function render(){
        $vite = $this->viteService;
        $rules = FormRules::getRulesForFrontend();
        $validationRules = json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
        require TEMPLATE_PATH . '/app.php';
    }
}
?>