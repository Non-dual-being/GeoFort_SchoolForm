<?php

declare(strict_types=1);

namespace GeoFort\Validation;

final class FormRules
{
    public const ALLOWED_COUNTRIES = [
        'Nederland',
        'België',
    ];

    public const ALLOWED_VALIDATORS = [
        'email'
    ];

    public const COUNTRY_DEPENDENT_FIELDS = [
        'postcode',
        'schoolTelefoonnummer',
        'contactpersoonTelefoonnummer',
    ];

    public const GEOFORT_DISCOVERY_OTHER_OPTION = 'Anders / onbekend';

    public const GEOFORT_DISCOVERY_OTHER_OPTION_VALIDATE_VALUE = 'Anders / onbekend:';

    public const GEOFORT_DISCOVERY_OPTIONS = [
        'Eerder met school GeoFort bezocht',
        'Via Google of een andere zoekmachine',
        'Via de website van GeoFort',
        'Via Minecraft GeoCraft',
        'Via een collega of andere school',
        'Via de Museumvereniging, Museumkaart of Museumkids',
        'Via een kortings- of actieplatform',
        'Via promotiemateriaal, zoals een brochure, flyer of bord',
        'Via recreatieaanbod in de omgeving',
        'Via social media',
        'Via een werkgerelateerd evenement',
        'Omdat GeoFort in de buurt ligt',
        self::GEOFORT_DISCOVERY_OTHER_OPTION,
    ];

    private const NL_SCHOOL_PHONE_RULE = [
        'min' => 9,
        'max' => 25,
        'minDigits' => 10,
        'maxDigits' => 11,
        'required' => true,
        'regex' => "/^(?:0[1-9](?:[\s-]?\d){8}|(?:\+\s?|00)31[\s-]?[1-9](?:[\s-]?\d){8})$/u",
    ];

    private const BE_SCHOOL_PHONE_RULE = [
        'min' => 8,
        'max' => 25,
        'minDigits' => 9,
        'maxDigits' => 11,
        'required' => true,
        'regex' => "/^(?:0[1-9](?:[\s-]?\d){7,8}|(?:\+\s?|00)32[\s-]?[1-9](?:[\s-]?\d){7,8})$/u",
    ];

    private const NL_CONTACT_PHONE_RULE = [
        'min' => 10,
        'max' => 25,
        'minDigits' => 10,
        'maxDigits' => 11,
        'required' => true,
        'regex' => "/^(?:06(?:[\s-]?\d){8}|(?:\+\s?|00)31[\s-]?6(?:[\s-]?\d){8})$/u",
    ];

    private const BE_CONTACT_PHONE_RULE = [
        'min' => 10,
        'max' => 25,
        'minDigits' => 10,
        'maxDigits' => 11,
        'required' => true,
        'regex' => "/^(?:04[5-9]\d(?:[\s-]?\d){6}|(?:\+\s?|00)32[\s-]?4[5-9]\d(?:[\s-]?\d){6})$/u",
    ];

    private const CONTACT_EMAIL_RULE = [
        'min'       => 3,
        'max'       => 254,
        'required'  => true,
        'validator' => 'email'
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

        'schoolTelefoonnummer' => [
            'Nederland' => self::NL_SCHOOL_PHONE_RULE,
            'België' => self::BE_SCHOOL_PHONE_RULE,
        ],

        'contactpersoonTelefoonnummer' => [
            'Nederland' => self::NL_CONTACT_PHONE_RULE,
            'België' => self::BE_CONTACT_PHONE_RULE,
        ],
        'contactpersoonVoornaam' => [
            'min' => 1,
            'max' => 50,
            'required' => true,
            'regex' => "/^(?=.{1,50}$)\p{L}+(?:[ .\-']\p{L}+)*$/u"
        ],
        'contactpersoonAchternaam' => [
            'min' => 1,
            'max' => 50,
            'required' => true,
            'regex' => "/^(?=.{1,50}$)\p{L}+(?:[ .\-']\p{L}+)*$/u"
        ],
        'email' => self::CONTACT_EMAIL_RULE,
        'hoeKentUGeoFort' => [
            'min' => 0,
            'max' => 120,
            'customMin' => 2,
            'customMax' => 80,
            'required' => false,

            /**
             * Deze regex geldt alleen voor de custom tekst na:
             * "Anders / onbekend:"
             */

            'regex' => "/^[\p{L}\p{M}0-9][\p{L}\p{M}0-9\s.,'’\"()&\/+_!?-]*$/u",

            /**
             * Extra metadata voor frontend én backend-validatie.
             */
            'allowedValues' => self::GEOFORT_DISCOVERY_OPTIONS,
            'otherOption' => self::GEOFORT_DISCOVERY_OTHER_OPTION,
        ],

        'cjpPasGebruik' => [
            'min' => 2,
            'max' => 3,
            'required' => true,
            'regex' => "/^(ja|nee)$/u",
        ],

        'cjpContactpersoonNaam' => [
            'min' => 1,
            'max' => 80,
            'required' => true,
            'regex' => "/^(?=.{1,80}$)\p{L}+(?:[ .\-']\p{L}+)*$/u",
        ],

        'cjpPasnummer' => [
            'min' => 8,
            'max' => 9,
            'required' => true,
            'regex' => "/^\d{8,9}$/u",
        ],
    ];

private static function normalizeRulesForFrontend(array $config): array
{
    $rule = [
        'min' => (int) $config['min'],
        'max' => (int) $config['max'],
        'required' => (bool) $config['required'],
    ];

    if (array_key_exists('regex', $config)) {
        $matches = [];
        preg_match('/^\/(.*)\/([a-z]*)$/', $config['regex'], $matches);

        $rule['pattern'] = $matches[1] ?? '';
        $rule['flags'] = $matches[2] ?? '';
    }

    if (array_key_exists('minDigits', $config)) {
        $rule['minDigits'] = (int) $config['minDigits'];
    }

    if (array_key_exists('maxDigits', $config)) {
        $rule['maxDigits'] = (int) $config['maxDigits'];
    }

    if (array_key_exists('customMin', $config)) {
        $rule['customMin'] = (int) $config['customMin'];
    }

    if (array_key_exists('customMax', $config)) {
        $rule['customMax'] = (int) $config['customMax'];
    }

    if (array_key_exists('validator', $config)) {
        $rule['validator'] = (string) $config['validator'];
    }

    if (array_key_exists('allowedValues', $config)) {
        $rule['allowedValues'] = array_values($config['allowedValues']);
    }

    if (array_key_exists('otherOption', $config)) {
        $rule['otherOption'] = (string) $config['otherOption'];
    }

    return $rule;
}

    public static function isAllowedCountry(string $country): bool
    {
        return in_array($country, self::ALLOWED_COUNTRIES, true);
    }

    public static function isCountryDependentField(string $field): bool
    {
        return in_array($field, self::COUNTRY_DEPENDENT_FIELDS, true);
    }

    public static function getRuleForField(string $field, ?string $country = null): array
    {
        if (!array_key_exists($field, self::RULES)) {
            throw new \InvalidArgumentException("Invalid field");
        }

        if (!self::isCountryDependentField($field)) {
            return self::RULES[$field];
        }

        if ($country === null || !self::isAllowedCountry($country)) {
            throw new \InvalidArgumentException("Invalid country");
        }

        return self::RULES[$field][$country];
    }

    public static function getRulesForFrontend(): array
    {
        $out = [];

        foreach (self::RULES as $field => $config) {
            if (self::isCountryDependentField($field)) {
                foreach ($config as $country => $countryConfig) {
                    $out[$field][$country] =
                        self::normalizeRulesForFrontend($countryConfig);
                }

                continue;
            }

            $out[$field] = self::normalizeRulesForFrontend($config);
        }

        return $out;
    }
}