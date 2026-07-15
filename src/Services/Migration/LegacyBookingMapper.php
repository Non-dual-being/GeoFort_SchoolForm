<?php

declare(strict_types=1);

namespace GeoFort\Services\Migration;

use DateTimeImmutable;
use GeoFort\Booking\BookingPolicy;
use GeoFort\Booking\BookingProgramConfig;

final class LegacyBookingMapper
{
    private const SCHOOL_TYPES = [
        'Primair Onderwijs' => 'primairOnderwijs',
        'Voortgezet Onderwijs onderbouw' => 'voortgezetOnderbouw',
        'Voortgezet Onderwijs bovenbouw' => 'voortgezetBovenbouw',
    ];

    /** @var array<int, string> */
    private array $landByLegacyId;

    /** @param array<int, string> $landByLegacyId */
    public function __construct(array $landByLegacyId = [])
    {
        $this->landByLegacyId = $landByLegacyId;
    }

    /** @param array<string, mixed> $legacy @return array<string, mixed> */
    public function map(array $legacy): array
    {
        $id = (int) ($legacy['id'] ?? 0);
        $errors = $warnings = $errorCodes = $warningCodes = [];
        $addError = static function (string $code, string $message) use (&$errors, &$errorCodes): void {
            $errors[] = $message; $errorCodes[] = $code;
        };
        $addWarning = static function (string $code, string $message) use (&$warnings, &$warningCodes): void {
            $warnings[] = $message; $warningCodes[] = $code;
        };
        if ($id <= 0 || filter_var($legacy['id'] ?? null, FILTER_VALIDATE_INT) === false) {
            $addError('INVALID_LEGACY_ID', 'Ongeldig legacy-ID.');
        }

        $hasHtmlEntities = false;
        $text = static function (string $field, bool $multiline = false) use ($legacy, &$hasHtmlEntities): ?string {
            if (($legacy[$field] ?? null) === null || trim((string) $legacy[$field]) === '') return null;
            $value = (string) $legacy[$field];
            if (preg_match('/&(?:#\d+|#x[0-9a-f]+|[a-z][a-z0-9]+);/i', $value) === 1) $hasHtmlEntities = true;
            $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($multiline) $value = str_replace(["\r\n", "\r"], "\n", $value);
            return trim($value);
        };

        $sector = self::SCHOOL_TYPES[trim((string) ($legacy['schooltype'] ?? ''))] ?? null;
        if ($sector === null) $addError('UNKNOWN_SCHOOL_TYPE', 'Onbekend schooltype: ' . self::display($legacy['schooltype'] ?? null));
        $program = trim((string) ($legacy['programma_duur'] ?? ''));
        if (!BookingProgramConfig::programExists($program)) {
            $addError('UNKNOWN_PROGRAM', 'Onbekend programma: ' . self::display($program));
        } elseif ($sector !== null && !BookingProgramConfig::isProgramAllowedForSchoolSector($program, $sector)) {
            $addError('PROGRAM_NOT_ALLOWED_FOR_SECTOR', "Programma {$program} is niet geldig voor sector {$sector}");
        }

        $choice = trim((string) ($legacy['keuze_module'] ?? ''));
        if ($program === 'ochtend' && $choice === 'Standaard-Ochtend-Programma-PO') {
            $choice = '';
            $addWarning('STANDARD_MORNING_MODULE_TO_NULL', 'Historische ochtendstandaard wordt als lege keuzemodule opgeslagen.');
        } elseif ($choice !== '' && !array_key_exists($choice, BookingProgramConfig::MODULE_LABELS)) {
            $addError('UNKNOWN_MODULE', 'Onbekende keuzemodule: ' . self::display($choice));
        } elseif ($choice !== '' && ($sector === null || !BookingProgramConfig::isChoiceModuleConfiguredForSelection($choice, $sector, $program))) {
            $context = "legacy-ID {$id}, sector " . self::display($sector) . ", programma " . self::display($program) . ", module {$choice}";
            if ($sector !== null && in_array($choice, BookingProgramConfig::getStandardModulesForSelection($sector, $program), true)) {
                $addWarning('HISTORICAL_MODULE_NOW_STANDARD', 'Historische keuzemodule is tegenwoordig een standaardmodule; waarde behouden: ' . $context);
            } else {
                $addWarning('HISTORICAL_MODULE_OUTSIDE_CURRENT_CONFIG', 'Historische module past niet binnen de huidige sector/programmaconfiguratie; waarde behouden: ' . $context);
            }
        }

        $possibleBelgian = $this->isPossiblyBelgian($legacy, $text('schoolnaam'), $text('plaats'));
        if ($possibleBelgian && !isset($this->landByLegacyId[$id])) $addWarning('POSSIBLE_NON_DUTCH_COUNTRY', 'Mogelijk buitenlands record; landmapping vereist handmatige controle.');
        $status = trim((string) ($legacy['status'] ?? ''));
        if (!BookingPolicy::isAllowedStatus($status)) $addError('INVALID_STATUS', 'Ongeldige status: ' . self::display($status));
        $visitDate = trim((string) ($legacy['bezoekdatum'] ?? ''));
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $visitDate);
        if ($date === false || $date->format('Y-m-d') !== $visitDate) $addError('INVALID_VISIT_DATE', 'Ongeldige bezoekdatum.');
        $email = trim((string) ($legacy['email'] ?? ''));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) $addWarning('INVALID_EMAIL_FORMAT', 'E-mailadres heeft een afwijkend of leeg formaat.');

        $comments = $text('opmerkingen', true);
        $discovery = $text('hoe_kent_u_geofort');
        if (mb_strlen($comments ?? '', 'UTF-8') > 600) $addWarning('COMMENTS_OVER_FORM_LIMIT', 'Opmerkingen langer dan 600 tekens; historische waarde blijft volledig behouden.');
        if (mb_strlen($discovery ?? '', 'UTF-8') > 120) $addWarning('DISCOVERY_OVER_FORM_LIMIT', 'Hoe-kent-u-GeoFortwaarde langer dan 120 tekens; historische waarde blijft volledig behouden.');
        $students = self::integer($legacy['aantal_leerlingen'] ?? null);
        $supervisors = self::integer($legacy['aantal_begeleiders'] ?? null);
        foreach (['aantal_leerlingen','aantal_begeleiders','remise_break','kazerne_break','fortgracht_break','glas_limonade','waterijsje','remise_lunch','eigen_picknick'] as $field) {
            if (self::integer($legacy[$field] ?? null) === null) $addError('INVALID_NUMERIC_VALUE', "Ongeldige numerieke waarde voor {$field}.");
        }
        if (!in_array(self::integer($legacy['cjp_korting'] ?? null), [0, 1], true)) $addError('INVALID_CJP_VALUE', 'cjp_korting moet exact 0 of 1 zijn.');
        if ($students === null || $students <= 0) $addWarning('MISSING_POSITIVE_STUDENT_COUNT', 'Aanvraag zonder positief leerlingenaantal.');
        if ($supervisors === null || $supervisors <= 0) $addWarning('MISSING_POSITIVE_SUPERVISOR_COUNT', 'Aanvraag zonder positief begeleidersaantal.');

        $selections = $sector === null ? [] : $this->mapSelections($id, $sector, $legacy, $errors, $errorCodes);
        if ($selections === []) $addError('NO_EDUCATION_SELECTION', 'Geen onderwijsselectierecord voor aanvraag.');
        return [
            'booking' => [
                'status'=>$status,'schoolnaam'=>$text('schoolnaam'),'land'=>$this->landByLegacyId[$id] ?? 'Nederland','adres'=>$text('adres'),
                'postcode'=>trim((string)($legacy['postcode'] ?? '')),'plaats'=>$text('plaats'),'school_telefoonnummer'=>trim((string)($legacy['school_telefoon'] ?? '')),
                'contactpersoon_telefoonnummer'=>trim((string)($legacy['contact_telefoon'] ?? '')),'contactpersoon_voornaam'=>$text('voornaam_contactpersoon'),
                'contactpersoon_achternaam'=>$text('achternaam_contactpersoon'),'email'=>$email,'bezoekdatum'=>$visitDate,'hoe_kent_u_geofort'=>$discovery,
                'opmerkingen'=>$comments,'cjpPasGebruik'=>((int)($legacy['cjp_korting'] ?? 0))===1?'ja':'nee','cjpContactpersoonNaam'=>null,'cjpPasnummer'=>null,
                'onderwijs_sector'=>$sector,'programma'=>$program,'keuzemodule_key'=>$choice===''?null:$choice,'aantal_leerlingen'=>$students,'aantal_begeleiders'=>$supervisors,
                'remise_break'=>self::integer($legacy['remise_break']??null)??0,'kazerne_break'=>self::integer($legacy['kazerne_break']??null)??0,
                'fortgracht_break'=>self::integer($legacy['fortgracht_break']??null)??0,'glas_limonade'=>self::integer($legacy['glas_limonade']??null)??0,
                'waterijsje'=>self::integer($legacy['waterijsje']??null)??0,'remise_lunch'=>self::integer($legacy['remise_lunch']??null)??0,
                'eigen_picknick'=>self::integer($legacy['eigen_picknick']??null)??0,'voorwaarden_akkoord'=>0,'voorwaarden_akkoord_op'=>null,
            ],
            'selections'=>$selections,'errors'=>array_values(array_unique($errors)),'warnings'=>array_values(array_unique($warnings)),
            'error_codes'=>array_values(array_unique($errorCodes)),'warning_codes'=>array_values(array_unique($warningCodes)),
            'has_html_entities'=>$hasHtmlEntities,'possible_belgian'=>$possibleBelgian,
        ];
    }

    /** @param array<string, mixed> $booking @param array<string, int|null> $capacities @return list<string> */
    public static function validateTargetCharacterCapacities(array $booking, array $capacities): array
    {
        $errors=[]; foreach($booking as $column=>$value){$capacity=$capacities[$column]??null;if($capacity!==null&&is_string($value)&&mb_strlen($value,'UTF-8')>$capacity)$errors[]="Waarde voor {$column} is langer dan doelkolomcapaciteit {$capacity}.";} return $errors;
    }

    /** @param array<string, mixed> $legacy @param list<string> $errors @param list<string> $codes @return list<array<string, mixed>> */
    private function mapSelections(int $id, string $sector, array $legacy, array &$errors, array &$codes): array
    {
        $pairs=[];
        if($sector==='primairOnderwijs'){$level=trim((string)($legacy['niveau1']??''));if(!in_array($level,['regulier','speciaal'],true)){$errors[]=$level===''?'Ontbrekend niveau.':'Onbekend level: '.$level;$codes[]='UNKNOWN_LEVEL';return[];}$groups=[];foreach(range(1,5)as$p){$v=trim((string)($legacy['leeftijdsgroep'.$p]??''));if($v!=='')$groups[]=$v;}$pairs[]=[$level,$groups];}
        else{foreach(range(1,3)as$p){$rawLevel=trim((string)($legacy['niveau'.$p]??''));$rawGroups=trim((string)($legacy['leeftijdsgroep'.$p]??''));if($rawLevel===''){if($rawGroups!==''){$errors[]="Ontbrekend niveau bij leeftijdsgroep{$p}.";$codes[]='GROUP_WITHOUT_LEVEL';}continue;}$level=$this->mapVoLevel($sector,$rawLevel);if($level===null){$errors[]="Onbekend level op positie {$p}: {$rawLevel}";$codes[]='UNKNOWN_LEVEL';continue;}$groups=$rawGroups===''?[]:array_values(array_filter(array_map('trim',explode(',',$rawGroups)),static fn(string $v):bool=>$v!==''));$pairs[]=[$level,$groups];}}
        $rows=[];$seen=[];foreach($pairs as$levelIndex=>$pair){[$level,$groups]=$pair;if($groups===[]){$errors[]="Level {$level} zonder groepen.";$codes[]='LEVEL_WITHOUT_GROUPS';continue;}foreach($groups as$groupIndex=>$rawGroup){$group=$this->mapGroup($rawGroup);$configured=BookingProgramConfig::SCHOOL_LEVELS[$sector][$level]['groups'][$group??'']??null;if($group===null||!is_string($configured)){$errors[]="Onbekende groep voor {$level}: {$rawGroup}";$codes[]='GROUP_NOT_ALLOWED_FOR_LEVEL';continue;}$key=$id.'|'.$level.'|'.$group;if(isset($seen[$key])){$errors[]="Dubbele level/groupcombinatie na normalisatie: {$level}/{$group}";$codes[]='DUPLICATE_NORMALIZED_SELECTION';continue;}$seen[$key]=true;$rows[]=['sector_key'=>$sector,'sector_label'=>BookingProgramConfig::getSchoolSectorLabel($sector),'level_key'=>$level,'level_label'=>BookingProgramConfig::SCHOOL_LEVELS[$sector][$level]['label'],'level_position'=>$levelIndex+1,'group_key'=>$group,'group_label'=>$configured,'group_position'=>$groupIndex+1];}}
        return$rows;
    }

    private function mapVoLevel(string $sector,string $value):?string
    {
        if($sector==='voortgezetBovenbouw'){if(in_array($value,['VMBO','VMBO_BB_KB','VMBO_GL_TL','VMBO Basis Kader','VMBO Gemengd Theoretisch','MAVO'],true))return'vmbo';if($value==='HAVO')return'havo';if($value==='VWO')return'vwo';if(in_array($value,['Praktijk Onderwijs','PraktijkOnderwijs'],true))return'praktijkOnderwijs';return null;}
        if(in_array($value,['VMBO_BB_KB','VMBO Basis Kader'],true))return'vmboBasisKader';if(in_array($value,['VMBO_GL_TL','VMBO Gemengd Theoretisch','MAVO'],true))return'vmboGemengdTheoretisch';if($value==='HAVO')return'havo';if($value==='VWO')return'vwo';if(in_array($value,['Praktijk Onderwijs','PraktijkOnderwijs'],true))return'praktijkOnderwijs';return null;
    }
    private function mapGroup(string $value):?string{if(preg_match('/^Groep ([5-8])$/u',$value,$m)===1)return'groep'.$m[1];if(preg_match('/^(VMBO|HAVO|Atheneum|Gymnasium) ([1-6])$/u',$value,$m)===1)return strtolower($m[1]).$m[2];if(preg_match('/^(?:Praktijkonderwijs|Praktijk Onderwijs|Praktijk) ([1-5])$/u',$value,$m)===1)return'praktijk'.$m[1];return null;}
    /** @param array<string, mixed> $legacy */
    private function isPossiblyBelgian(array $legacy,?string $school,?string $place):bool{foreach(['school_telefoon','contact_telefoon']as$field){$phone=preg_replace('/[\s().-]+/','',(string)($legacy[$field]??''));if(strpos((string)$phone,'+32')===0||strpos((string)$phone,'0032')===0)return true;}if(preg_match('/^\d{4}$/',trim((string)($legacy['postcode']??'')))===1)return true;return preg_match('/\b(Belgi(?:ë|e)|Belgisch|Vlaanderen|Antwerpen|Brussel|Gent|Leuven|Hasselt)\b/iu',($school??'').' '.($place??''))===1;}
    private static function integer($value):?int{return filter_var($value,FILTER_VALIDATE_INT)===false?null:(int)$value;}
    private static function display($value):string{$value=trim((string)$value);return$value===''?'[leeg]':$value;}
}
