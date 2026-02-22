<?php
declare(strict_types=1);
namespace GeoFort\Services;

final class ViteService
{
    private bool $isDev;
    private string $buildPath;

    public function __construct(string $envState, string $buildPath = __DIR__ . '/../../public/build')
    {
        $this->isDev = ($envState === 'development');
        $this->buildPath = $buildPath;
    }

    public function renderTags(string $entryPoint): string 
    {
        if ($this->isDev) return $this->renderDevTags($entryPoint);
        return $this->renderProdTags($entryPoint);
    }

    private function renderDevTags(string $entry): string
    {
        $host = 'https://onderwijsformulier.test:5173';
        return sprintf(
            '<script type="module" src="%s/@vite/client"></script>' 
            . PHP_EOL . 
            '<script type="module" src="%s/%s"></script>',
            $host,
            $host,
            $entry
        );
    }

    private function renderProdTags(string $entry): string
    {
        $manifestPath = $this->buildPath . '/.vite/manifest.json';
        if (!file_exists($manifestPath)) $manifestPath = $this->buildPath . '/manifest.json';

        if (!file_exists($manifestPath)) return '<!-- vite Manifest not found . Run nmp rund build -->';

        $manifest = json_decode(file_get_contents($manifestPath), true);
        $data = $manifest[$entry] ?? null;

        if (!$data) return '';
        $html = '';

        /**css */
        if (!empty($data['css'])){
            $cssFiles = $data['css'];
            foreach($cssFiles as $cssFile){
                $html .= sprintf('<link rel="stylesheet" href="/build/%s">' . PHP_EOL, $cssFile);
            }
        }

        $html .= sprintf('<script type="module" src="/build/%s"></script>', $data['file']);

        return $html;

    }


}

/**
 * PHP_EOL is a cross platform way to take a newline
 */

?>