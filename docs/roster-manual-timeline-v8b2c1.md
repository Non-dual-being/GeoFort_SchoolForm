# V8B2C1: backend Dagindeling

Deze run voegt uitsluitend opslag en servervalidatie voor zelfstandige conceptroosters toe. Een ronde is een tijdvak. Modules, locaties, groepen en docenten blijven onderdeel van de sessielaag.

## Contract voor V8B2C2

Gebruik het bestaande `POST /api/admin/rosters/manual-program-save.php`, met de bestaande private sessie en `X-CSRF-Token` voor `create-roster-plan`:

```json
{
  "planId": 123,
  "expectedRevision": 2,
  "rounds": [
    { "type": "welcome", "label": "Ontvangst", "startTime": "10:00", "endTime": "10:15" },
    { "type": "round", "label": "Ronde 1", "startTime": "10:15", "endTime": "11:00" }
  ]
}
```

- `planId` en `expectedRevision` zijn positieve gehele getallen.
- Stuur `moduleKeys`, `rounds` of beide. Een weggelaten veld blijft intact. Expliciet `null` is ongeldig; een lege tijdlijn is eveneens ongeldig.
- Opslag gebruikt dezelfde geordende array en camelCase-tijdvelden in `roster_manual_plan_context.rounds_json`. Dit volgt de bestaande rooster-API en `RosterGenerationRound`.
- Een blok bevat uitsluitend `type`, `label`, `startTime`, `endTime`. Types: `welcome`, `round`, `break`, `lunch`, `departure`.
- De response blijft `{ "ok": true, "data": { "plan": ... } }`. Ook `GET /api/admin/rosters/show.php?id=123` levert `plan.planning.rounds` en `plan.planning.roundsSource` (`default` of `saved`). Deze velden worden alleen toegevoegd wanneer er een zelfstandige context is.
- Gebruik na opslaan de nieuwe `plan.revision`. Opslag van beide velden is één transactie met één revisieverhoging.
- Bestaande wizardblokkades gelden ook voor dagindeling: alleen zelfstandige conceptroosters zonder sessies. Booking-based roosters worden afgewezen. Bestaande `MANUAL_PROGRAM_*`-foutcodes blijven behouden.
- `planning.generation.defaultRounds` blijft het bestaande generatorcontract. V8B2C2 gebruikt voor de dagindeling `planning.rounds`.

## Defaults en validatie

Bij SQL `NULL` of een nog ontbrekende JSON-kolom gebruikt lezen `RosterGenerationTemplateProvider::defaultTimeline()`. De lesblokken komen ongewijzigd uit `defaultRounds()`. Aankomst begint bij `BookingProgramConfig::PROGRAMS[program].beginTijd`; vertrek eindigt bij `eindTijd`. De tussenruimtes tussen de bestaande lesblokken bepalen pauze en lunch. Het huidige dagtemplate plaatst lunch tussen de derde en vierde ronde. Er zijn geen nieuwe vaste programmatijden toegevoegd.

`RosterPlanningConfig` blijft de bestaande module- en personeelsconfiguratie; deze bevat geen tijdtemplate. Het aantal gekozen modules verandert de tijdlijn niet. Defaults worden uitsluitend gelezen en niet automatisch opgeslagen. Bij ontbrekende templateprovider/configuratie kan de default leeg zijn. Een opgeslagen tijdlijn gaat altijd voor op defaults.

`RosterManualTimeline` valideert een echte geordende array, ondersteunde types, niet-lege tekstlabels van maximaal 160 tekens zonder besturingstekens, strikt `HH:MM` (00:00–23:59), start vóór einde, chronologische volgorde zonder overlap en minimaal één `round`. Labels worden getrimd en als tekst opgeslagen. Aansluitingen en gaten zijn toegestaan. Er is geen koppeling tussen het aantal rondes en modules en geen modulevalidatie per blok. Deze fundering begrenst handmatige tijden niet tot het standaardprogramma. De bestaande `day_start_time` en `day_end_time` worden bij een tijdlijnwijziging niet herschreven.

## Migratie

Na de V8B2B-migratie volgt `database/sql/2026-09-26_add_roster_manual_rounds.sql`. Deze voegt uitsluitend nullable `rounds_json` toe. Lezen ondersteunt ook oudere schema's en behoudt aanwezige `module_keys_json`-waarden. Schrijven van dagindeling vereist de nieuwe kolom.

De forwardmigratie is op de bestaande lokale `geoform_roster_test` uitgevoerd. Daar is geen handmatige vervolgmigratie nodig. Deze database draait feitelijk op **MySQL 8.4.7, poort 3306**. Echte MariaDB-verificatie is aanvullend uitgevoerd op **MariaDB 11.4.9, poort 3307**, in automatisch opgeruimde disposable databases. Andere omgevingen zijn niet gemigreerd.

Het afzonderlijke rollbackbestand volgt de V8B2B-conventie en is niet uitgevoerd; het verwijdert de nieuwe kolom en de inhoud daarvan.

## Gewijzigde bestanden in deze run

- `database/sql/2026-09-26_add_roster_manual_rounds.sql`
- `database/sql/2026-09-26_rollback_roster_manual_rounds.sql`
- `src/Services/Dashboard/Roster/RosterManualTimeline.php`
- `src/Services/Dashboard/Roster/RosterGenerationTemplateProvider.php`
- `src/Services/Dashboard/Roster/RosterPlanService.php`
- `src/Services/Sql/RosterPlanSqlRepository.php`
- `src/Services/Http/Api/Admin/DashboardRosterManualProgramSaveRequest.php`
- `src/Services/Http/Api/Admin/DashboardRosterManualProgramSaveAction.php`
- `resources/js/admin/types/roster.ts`
- `scripts/tests/RosterManualTimelineDomainTest.php`
- `scripts/tests/RosterManualRoundsMariaDbIntegrationTest.php`
- `docs/roster-manual-timeline-v8b2c1.md`

## Uitgevoerde controles

PHP-tests zijn uitgevoerd met `C:/wamp64/bin/php/php8.4.15/php.exe scripts/tests/<bestand>`.

| Bestand | Resultaat |
| --- | --- |
| `RosterManualTimelineDomainTest.php` | Groen, 88 controles op validatie, requests en defaults |
| `RosterPlanFoundationDomainTest.php` | Groen |
| `RosterPlanningConfigDomainTest.php` | Groen |
| `RosterCanonicalModuleNamesDomainTest.php` | Groen |
| `DashboardRosterHttpContractTest.php` | Groen |
| `DashboardRosterManualProgramBackendContractTest.php` | Groen |
| `DashboardRosterManualProgramFrontendContractTest.php` | Groen |
| `DashboardRosterDeleteBackendContractTest.php` | Groen |
| `DashboardRosterFrontendContractTest.php` | Groen |
| `RosterManualRoundsMariaDbIntegrationTest.php` | Groen op MariaDB en op `geoform_roster_test`, 45 controles per uitvoering |
| `RosterManualPlanMariaDbIntegrationTest.php` | Groen op MariaDB |
| `RosterManualProgramMariaDbIntegrationTest.php` | Groen op MariaDB |
| `RosterPlanMariaDbIntegrationTest.php` | Groen op MariaDB |
| `RosterSessionMariaDbIntegrationTest.php` | Groen op MariaDB |
| `RosterAutoGeneratorMariaDbIntegrationTest.php` | Groen op MariaDB |
| `DashboardRosterManualCreateFrontendContractTest.php` | Bestaande V8B2A-test faalt op oude knoptekst `Basis opslaan en rooster openen`; de al aanwezige V8B2B-view gebruikt `Verder naar Programma`. Test en view zijn in deze run niet aangepast. |

Voor MariaDB zijn `APP_ENV=testing`, `STATUS_TEST_DB_CONFIRM=YES_DISPOSABLE`, `STATUS_TEST_DB_HOST=127.0.0.1`, `STATUS_TEST_DB_PORT=3307`, `STATUS_TEST_DB_USER=root` ingesteld, zonder `STATUS_TEST_DB_NAME`. De nieuwe integratietest is daarnaast met `STATUS_TEST_DB_NAME=geoform_roster_test`, poort 3306 en de lokale worktree-testcredentials uitgevoerd. Deze modus gebruikt het bestaande schema, ruimt alleen eigen fixtures op en vergelijkt bestaande boekingen, plannen en contexten vóór en na de test.

`php -l` is groen voor de zes genoemde PHP-backendbestanden en de twee nieuwe testbestanden. `pnpm exec vue-tsc --noEmit` en `pnpm build` (inclusief `vue-tsc -b`) zijn groen. Alleen de bekende onopgeloste verwijzing naar `form-heightlines.png` verschijnt bij de build. `git status --short` en `git diff --check` zijn uitgevoerd met exitcode 0; eerdere worktreewijzigingen zijn behouden. Git meldt alleen bestaande CRLF/LF-waarschuwingen voor twee eerder gewijzigde CSS-bestanden.
