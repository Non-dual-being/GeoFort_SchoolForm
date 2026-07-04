<?php

declare(strict_types=1);

namespace GeoFort\Services\Sql;

use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Services\Booking\Data\EducationSelectionData;
use PDO;
use PDOException;
use RuntimeException;

final class EducationSelectionSqlService
{
    public function __construct(private PDO $pdo)
    {}

    /**
     * Slaat de onderwijsselectie op voor één aanvraag.
     *
     * Belangrijk:
     * - De hoofdaanvraag is op dit moment al opgeslagen in `aanvragen`.
     * - `$requestId` is de id van die hoofdaanvraag.
     * - `$educationSelection` bevat de gevalideerde sector, levels en groepen.
     *
     * Deze methode maakt één database-rij per gekozen groep/leerjaar.
     *
     * Voorbeeld:
     * selectedLevels = ['regulier']
     * selectedGroupsByLevel = [
     *   'regulier' => ['groep5', 'groep6']
     * ]
     *
     * Dan worden twee rijen opgeslagen:
     * - regulier + groep5
     * - regulier + groep6
     */
    public function insertForRequest(
        int $requestId,
        EducationSelectionData $educationSelection,
    ): void {
        try {
            /**
             * SQL-template voor één selectie-rij.
             *
             * Deze query wordt voorbereid met placeholders.
             * Daarna voeren we dezelfde prepared statement meerdere keren uit:
             * één keer per gekozen groep/leerjaar.
             */
            $insert = "
                INSERT INTO aanvraag_onderwijs_selecties (
                    aanvraag_id,
                    sector_key,
                    sector_label,
                    level_key,
                    level_label,
                    level_position,
                    group_key,
                    group_label,
                    group_position
                )
                VALUES (
                    :aanvraagId,
                    :sectorKey,
                    :sectorLabel,
                    :levelKey,
                    :levelLabel,
                    :levelPosition,
                    :groupKey,
                    :groupLabel,
                    :groupPosition
                )
            ";

            /**
             * Prepared statement maken.
             *
             * Dit is veiliger dan waarden rechtstreeks in SQL plakken:
             * - voorkomt SQL injection
             * - laat PDO de waardes correct binden
             * - dezelfde query kan efficiënt meerdere keren worden uitgevoerd
             */
            $stmt = $this->pdo->prepare($insert);

            /**
             * Sector-key komt uit de gevalideerde EducationSelectionData.
             *
             * Voorbeelden:
             * - primairOnderwijs
             * - voortgezetOnderbouw
             * - voortgezetBovenbouw
             */
            $sector = $educationSelection->sector;

            /**
             * Leesbaar sectorlabel ophalen uit BookingProgramConfig.
             *
             * Voorbeeld:
             * sector_key   = primairOnderwijs
             * sector_label = Primair onderwijs
             */
            $sectorLabel = BookingProgramConfig::getSchoolSectorLabel($sector);

            /**
             * Loop over alle gekozen onderwijsniveaus.
             *
             * `$levelIndex` is de numerieke positie in de array.
             * Omdat array-indexen bij 0 beginnen, slaan we later `$levelIndex + 1` op.
             *
             * `$levelKey` is de technische key, bijvoorbeeld:
             * - regulier
             * - havo
             * - vwo
             */
            foreach ($educationSelection->selectedLevels as $levelIndex => $levelKey) {
                /**
                 * Leesbaar level-label ophalen uit BookingProgramConfig.
                 *
                 * Voorbeeld:
                 * level_key   = regulier
                 * level_label = Regulier basisonderwijs
                 */
                $levelLabel = $this->getLevelLabel($sector, $levelKey);

                /**
                 * Groepen ophalen die bij dit gekozen level horen.
                 *
                 * Als er om welke reden dan ook geen groepen zijn voor dit level,
                 * gebruiken we een lege array.
                 *
                 * In normale flow hoort dit niet leeg te zijn,
                 * want de validator controleert minimaal aantal groepen.
                 */
                $groups = $educationSelection->selectedGroupsByLevel[$levelKey] ?? [];

                /**
                 * Loop over alle gekozen groepen binnen dit level.
                 *
                 * Voorbeeld:
                 * levelKey = regulier
                 * groups = ['groep5', 'groep6']
                 */
                foreach ($groups as $groupIndex => $groupKey) {
                    /**
                     * Voer de insert uit voor één combinatie:
                     *
                     * aanvraag + sector + level + groep
                     */
                    $stmt->execute([
                        ':aanvraagId'    => $requestId,

                        /**
                         * Technische en leesbare sector.
                         */
                        ':sectorKey'     => $sector,
                        ':sectorLabel'   => $sectorLabel,

                        /**
                         * Technische en leesbare level-waarde.
                         */
                        ':levelKey'      => $levelKey,
                        ':levelLabel'    => $levelLabel,

                        /**
                         * Positie van het level binnen de selectie.
                         * +1 omdat mensen tellen vanaf 1, arrays vanaf 0.
                         */
                        ':levelPosition' => $levelIndex + 1,

                        /**
                         * Technische en leesbare groep.
                         */
                        ':groupKey'      => $groupKey,
                        ':groupLabel'    => $this->getGroupLabel(
                            $sector,
                            $levelKey,
                            $groupKey,
                        ),

                        /**
                         * Positie van de groep binnen het gekozen level.
                         */
                        ':groupPosition' => $groupIndex + 1,
                    ]);
                }
            }
        } catch (PDOException $e) {
            /**
             * PDOException vangt databasefouten af.
             *
             * Voorbeelden:
             * - tabel bestaat niet
             * - kolomnaam klopt niet
             * - foreign key fout
             * - duplicate key fout
             */
            error_log('[SQL ERROR][EducationSelectionSqlService::insertForRequest]: ' . $e->getMessage());

            /**
             * Naar buiten toe gooien we een RuntimeException.
             *
             * Daardoor hoeft de bovenliggende service niet te weten
             * dat het specifiek om PDO ging.
             */
            throw new RuntimeException(
                'Onderwijsselectie kon niet worden opgeslagen',
                0,
                $e,
            );
        }
    }

    /**
     * Haalt het leesbare label van een level op uit BookingProgramConfig.
     *
     * Voorbeeld:
     * sector = primairOnderwijs
     * levelKey = regulier
     *
     * Geeft bijvoorbeeld terug:
     * Regulier basisonderwijs
     */
    private function getLevelLabel(string $sector, string $levelKey): string
    {
        /**
         * Zoek het level in de configuratie.
         *
         * Als sector of levelKey niet bestaat, wordt `$level` null.
         */
        $level = BookingProgramConfig::SCHOOL_LEVELS[$sector][$levelKey] ?? null;

        /**
         * Dit hoort niet te gebeuren als de validator goed heeft gewerkt.
         *
         * Als het toch gebeurt, is er waarschijnlijk een configuratiefout
         * of is de service aangeroepen met ongevalideerde data.
         */
        if (!is_array($level) || !isset($level['label'])) {
            throw new RuntimeException(
                "Ontbrekend level-label voor {$sector}.{$levelKey}",
            );
        }

        return (string) $level['label'];
    }

    /**
     * Haalt het leesbare label van een groep/leerjaar op uit BookingProgramConfig.
     *
     * Voorbeeld:
     * sector = primairOnderwijs
     * levelKey = regulier
     * groupKey = groep5
     *
     * Geeft bijvoorbeeld terug:
     * Groep 5
     */
    private function getGroupLabel(
        string $sector,
        string $levelKey,
        string $groupKey,
    ): string {
        /**
         * Zoek het group-label in de configuratie.
         *
         * De structuur is:
         * SCHOOL_LEVELS[sector][levelKey]['groups'][groupKey]
         */
        $label = BookingProgramConfig::SCHOOL_LEVELS[$sector][$levelKey]['groups'][$groupKey]
            ?? null;

        /**
         * Ook dit hoort niet te gebeuren na correcte validatie.
         *
         * Als dit toch gebeurt:
         * - groupKey hoort niet bij levelKey
         * - levelKey bestaat niet onder sector
         * - BookingProgramConfig mist data
         */
        if (!is_string($label)) {
            throw new RuntimeException(
                "Ontbrekend group-label voor {$sector}.{$levelKey}.{$groupKey}",
            );
        }

        return $label;
    }

    /**
 * @return array<int, array{
 *   sector_key: string,
 *   sector_label: string,
 *   level_key: string,
 *   level_label: string,
 *   level_position: int|string,
 *   group_key: string,
 *   group_label: string,
 *   group_position: int|string
 * }>
 */
    public function findByRequestId(int $requestId): array
    {
        try {
            $sql = "
                SELECT
                    sector_key,
                    sector_label,
                    level_key,
                    level_label,
                    level_position,
                    group_key,
                    group_label,
                    group_position
                FROM aanvraag_onderwijs_selecties
                WHERE aanvraag_id = :requestId
                ORDER BY
                    level_position ASC,
                    group_position ASC
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':requestId' => $requestId,
            ]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('[SQL ERROR][EducationSelectionSqlService::findByRequestId]: ' . $e->getMessage());

            throw new RuntimeException(
                'Onderwijsselectie kon niet worden opgehaald',
                0,
                $e,
            );
        }
    }
}