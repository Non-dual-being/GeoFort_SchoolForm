<?php
declare(strict_types=1);
namespace GeoFort\Services\Http\Url;
use GeoFort\Services\Http\Interfaces\BaseUrlProvider;

final class EnvironmentBaseUrlProvider implements BaseUrlProvider {
    private string $environment;
    private string $baseUrl;

    public function __construct(string $environment, string $baseUrl)
    {
        $environment = trim($environment);

        if (!in_array($environment, ['development', 'production'], true)) {
            throw new \InvalidArgumentException("incorrect env in " . __CLASS__);
        }
        
        $this->environment = $environment;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function getBaseUrl(): string 
    {
        return $this->baseUrl;
    }

    public function getEnvironment(): string 
    {
        return $this->environment;
    }
}
?>
