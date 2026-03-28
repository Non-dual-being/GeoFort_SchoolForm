<?php 
declare(strict_types=1);
namespace GeoFort\Validation;

final class FormRules
{
    public const RULES = [
        'schoolnaam' => [
            'min' => 1,
            'max' => 60,
            'required' => true,
            'regex' => "/^(?=.{1,60}$)(?!.*\d\s+\d)(?!.*\d{4,})[\p{L}0-9]+(?:[ .,'&-][\p{L}0-9]+)*$/u",
        ]
       
    ];

    public static function getRulesForFrontend(): array 
    {
        $out = [];
        foreach (self::RULES as $field => $config) {
            // Regex splitsen (/pattern/u -> pattern en u)
            preg_match('/^\/(.*)\/([a-z]*)$/', $config['regex'], $matches);
            
            $out[$field] = [
                'min' => (int)$config['min'],
                'max' => (int)$config['max'],
                'required' => (bool)$config['required'],
                'pattern' => $matches[1] ?? '',
                'flags' => $matches[2] ?? ''
            ];
        }

        return $out;
    }

}
/**
 * In matches[0] is the whole string
 * The () defines the match groups
 */
?>