<?php

declare(strict_types=1);

namespace GeoFort\Services\Migration;

use RuntimeException;

final class LegacyBookingDumpParser
{
    /** @return list<array<string, mixed>> */
    public function parseFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) throw new RuntimeException('Legacydump is niet leesbaar.');
        $sql = file_get_contents($path);
        if ($sql === false) throw new RuntimeException('Legacydump kon niet worden gelezen.');
        return $this->parseSql($sql);
    }

    /** @return list<array<string, mixed>> */
    public function parseSql(string $sql): array
    {
        $count = preg_match_all('/INSERT INTO `aanvragen`\s*\((.*?)\)\s*VALUES\s*/s', $sql, $matches, PREG_OFFSET_CAPTURE);
        if ($count === 0) {
            throw new RuntimeException('Geen expliciete INSERT voor de actuele tabel aanvragen gevonden.');
        }
        if ($count !== 1) throw new RuntimeException('Meerdere actuele aanvragen-INSERT-blokken worden niet ondersteund.');
        preg_match_all('/`([^`]+)`/', $matches[1][0][0], $columnMatches);
        $columns = $columnMatches[1];
        if ($columns === [] || !in_array('id', $columns, true)) throw new RuntimeException('Legacykolommen ontbreken.');
        $header=$matches[0][0];$valuesOffset=$header[1]+strlen($header[0]);$tuples = $this->splitTuples($this->extractValuesStatement($sql,$valuesOffset));
        $rows = [];
        foreach ($tuples as $tuple) {
            $values = $this->splitValues($tuple);
            if (count($values) !== count($columns)) throw new RuntimeException('Aantal waarden wijkt af van aantal legacykolommen.');
            $rows[] = array_combine($columns, $values);
        }
        return $rows;
    }

    private function extractValuesStatement(string $sql,int $offset):string
    {
        $quoted=false;$escaped=false;$depth=0;$length=strlen($sql);
        for($i=$offset;$i<$length;$i++){$char=$sql[$i];if($quoted){if($escaped)$escaped=false;elseif($char==='\\')$escaped=true;elseif($char==="'"){if($i+1<$length&&$sql[$i+1]==="'")$i++;else$quoted=false;}continue;}if($char==="'")$quoted=true;elseif($char==='(')$depth++;elseif($char===')')$depth--;elseif($char===';'&&$depth===0)return substr($sql,$offset,$i-$offset);}
        throw new RuntimeException('Aanvragen-INSERT mist een geldige afsluitende puntkomma.');
    }

    /** @return list<mixed> */
    private function splitValues(string $tuple): array
    {
        $values=[];$buffer='';$quoted=false;$wasQuoted=false;$escaped=false;$length=strlen($tuple);
        $finish=static function(string$value,bool$quoted){if(!$quoted){$value=trim($value);if(strcasecmp($value,'NULL')===0)return null;if(preg_match('/^-?\d+$/',$value)===1)return(int)$value;}return$value;};
        for($i=0;$i<$length;$i++){$char=$tuple[$i];if($quoted){if($escaped){$buffer.=['0'=>"\0",'n'=>"\n",'r'=>"\r",'t'=>"\t",'b'=>"\x08",'Z'=>"\x1a",'\\'=>'\\',"'"=>"'",'"'=>'"'][$char]??$char;$escaped=false;}elseif($char==='\\'){$escaped=true;}elseif($char==="'"){if($i+1<$length&&$tuple[$i+1]==="'"){$buffer.="'";$i++;}else{$quoted=false;}}else{$buffer.=$char;}continue;}if($char==="'"){$quoted=true;$wasQuoted=true;}elseif($char===','){$values[]=$finish($buffer,$wasQuoted);$buffer='';$wasQuoted=false;}elseif(!ctype_space($char)||$buffer!==''){$buffer.=$char;}}
        if($quoted||$escaped)throw new RuntimeException('Onvolledige gequote waarde in legacydump.');$values[]=$finish($buffer,$wasQuoted);return$values;
    }

    /** @return list<string> */
    private function splitTuples(string $input): array
    {
        $result=[];$buffer='';$depth=0;$quoted=false;$escaped=false;
        $length=strlen($input);
        for($i=0;$i<$length;$i++){$char=$input[$i];if($quoted){$buffer.=$char;if($escaped){$escaped=false;}elseif($char==='\\'){$escaped=true;}elseif($char==="'"){$quoted=false;}continue;}if($char==="'"){$quoted=true;if($depth>0)$buffer.=$char;continue;}if($char==='('){if($depth++>0)$buffer.=$char;continue;}if($char===')'){if(--$depth===0){$result[]=$buffer;$buffer='';}else{$buffer.=$char;}continue;}if($depth>0)$buffer.=$char;}
        if($quoted||$depth!==0)throw new RuntimeException('Onvolledige VALUES-structuur in legacydump.');
        return$result;
    }

    /** @param array<string, mixed> $row */
    public static function rowChecksum(array $row): string
    {
        ksort($row);
        return hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
