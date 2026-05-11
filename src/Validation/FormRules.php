<?php 
declare(strict_types=1);
namespace GeoFort\Validation;

final class FormRules
{
    public const ALLOWED_COUNTRIES = [
            'Nederland',
            'België',
    ];

    public const RULES = [
        'schoolnaam' => [
            'min' => 1,
            'max' => 60,
            'required' => true,
            'regex' => "/^(?=.{1,60}$)(?!.*\d\s+\d)(?!.*\d{4,})[\p{L}\p{M}0-9]+(?:[ .,'&-][\p{L}\p{M}0-9]+)*$/u",
        ],

        'land' => [
            'min' => 6,
            'max' => 9,
            'required' => true,
            'regex' => "/^(Nederland|België)$/u",
        ],

        'adres' => [
            'min' => 1,
            'max' => 100,
            'required' => true,
            'regex' => "/^(?=.{1,100}$)(?!.*\s{2})(?!.*[.,'\/-]{2})[\p{L}\p{M}0-9 .,'\/\-°]+$/u",
        ],

        'postcode' => [
            'Nederland' => [
                'min' => 6,
                'max' => 7,
                'required' => true,
                'regex' => "/^[1-9][0-9]{3}\s?[A-Za-z]{2}$/u",
            ],
            'België' => [
                'min' => 4,
                'max' => 4,
                'required' => true,
                'regex' => "/^[1-9][0-9]{3}$/u",
            ],
        ],

        'plaats' => [
            'min' => 1,
            'max' => 80,
            'required' => true,
            'regex' => "/^(?=.{1,80}$)(?!.*\s{2})(?!.*[-']{2})[\p{L}\p{M}]+(?:[ '-][\p{L}\p{M}]+)*$/u",
        ],
    ];

    private static function normalizeRulesForFronted(array $config): array {
        $matches = [];
        preg_match('/^\/(.*)\/([a-z]*)$/', $config['regex'], $matches);
        return [
            'min' => (int) $config['min'],
            'max' => (int) $config['max'],
            'required' => (bool) $config['required'],
            'pattern' => $matches[1] ?? '',
            'flags' => $matches[2] ?? '',
        ];
    }

    public static function isAllowedCountry(string $country): bool 
    {
        return in_array($country, self::ALLOWED_COUNTRIES, true);
    }

    public static function getPostCodeRuleForCountry(string $country): array
    {
        if (!self::isAllowedCountry($country)) throw new \InvalidArgumentException("Invalid Country");

        return self::RULES['postcode'][$country];
    }


    public static function getRulesForFrontend(): array 
    {
        $out = [];

        foreach(self::RULES as $field => $config) {
            if ($field === 'postcode'){
                foreach($config as $country => $postcodeRegex) {
                    $out[$field][$country] = self::normalizeRulesForFronted($postcodeRegex);
                }
                continue;
            }

            $out[$field] = self::normalizeRulesForFronted($config);
        }

        return $out;
    }

}
/**
 * In matches[0] is the whole string
 * The () defines the match groups
 */
?>