<?php
declare(strict_types=1);
namespace GeoFort\Services\Http;
use GeoFort\Services\Http\Interfaces\BaseUrlProvider;

final class GlobalBaseUrlProvider implements BaseUrlProvider {
    private string $environment;
    private string $baseUrl;
    private const MAP = [
        'development' => 'https://onderwijsformulier.test', 
        'production'  => 'https://onderwijsformulier.planetaryhealth.xyz'
    ];

    public function __construct(string $environment)
    {
        $environment = trim($environment);

        if (!array_key_exists($environment, self::MAP))
            throw new \InvalidArgumentException("incorrect env in " . __CLASS__);
        
        $this->environment = $environment;
        $this->baseUrl = self::MAP[$this->environment];
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