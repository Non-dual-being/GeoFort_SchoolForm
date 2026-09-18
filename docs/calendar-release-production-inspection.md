# Kalender-vrijgave: incident en lokale afronding (17–18 september 2026)

## Bevestigde productiebevindingen

De gebruiker heeft de productie-inspectie en het afzonderlijke schemaherstel uitgevoerd en de onderstaande resultaten teruggekoppeld. Codex heeft geen productieverbinding gebruikt en niets op productie gewijzigd.

| Onderdeel | Bevestigd resultaat |
| --- | --- |
| Actieve release | `20260908-135125-735870e-FmF4S3E0` |
| Actieve commit | `735870e43bedcf9fe5fada9e92b49a856b2fb09c` |
| Database | `onderwijsboeking_v2` |
| Databaseversie | MariaDB `10.11.14` |
| Schema vóór herstel | `generated_disabled_date_release_overrides` ontbrak |
| Aanwezige tabellen | `disabled_dates`, `booking_day_settings`, `calendar_date_change_history`, `calendar_date_change_history_dates` |
| Blokkadeschema | `disabled_dates.source` is `enum('generated','planner')`; `datum` is de primaire sleutel |
| Onderzochte records | `2027-02-22` en `2027-02-25` hadden type `school_vacation` en bron `generated` |
| Uitgevoerde migratie | `database/sql/2026-08-04_create_generated_disabled_date_release_overrides.sql`, ongewijzigd uit de actieve release |
| Schema na herstel | `SHOW CREATE TABLE` komt overeen met de bestaande migratie |
| Rijen direct na aanmaken | `0` |
| Blokkaderecords | Geen kalenderblokkades via SQL verwijderd |
| Databaseback-up | `onderwijsboeking_v2-20260917-132110.sql.gz`; gzip-integriteit en niet-lege inhoud gecontroleerd; ongecomprimeerd `422336` bytes |
| Deployment | Geen nieuwe deployment; actieve release en commit bleven ongewijzigd |
| Vrijgavebewijs | In de lokaal geïmporteerde export zijn beide vrijgaven en latere plannerblokkades aangetroffen; zie hieronder |
| Browsercontrole | Niet afzonderlijk bevestigd |

**De oorzaak is vastgesteld: de vrijgave van deze twee gegenereerde vakanties vereiste een tabel die ontbrak.** De actieve commit komt overeen met de uitgangscommit van de lokale reproductie. In die code schrijft vrijgave van `school_vacation` met bron `generated` eerst een persistente uitzondering in `generated_disabled_date_release_overrides`. De bevestigde productiegegevens vallen exact onder deze lokaal gereproduceerde fout. Handmatige records met bron `planner` slaan die stap over; daarom konden nieuw handmatig aangemaakte blokkades van beide typen wel worden vrijgegeven.

Het jaartal, type en de bron zijn voor beide records bevestigd. De historische productie-log bevatte geen SQLSTATE of drivercode; `42S02`/`1146` is het resultaat van de lokale reproductie. Bron `generated` bewijst niet welke seeder of eerdere import het record oorspronkelijk heeft aangemaakt; die specifieke herkomst is niet nodig voor het vaststellen van deze vrijgaveroute.

- **Schemaherstel productie: afgerond.** De beheerder heeft de bestaande migratie toegepast en het resulterende schema gecontroleerd. De lege tabel direct na aanmaken is het verwachte migratieresultaat; er zijn daarmee nog geen datums vrijgegeven.
- **Databasebewijs: beide oorspronkelijke vrijgaven vastgelegd, daarna opnieuw geblokkeerd.** De geïmporteerde productiegegevens bevatten passende overrides en vrijgave-audits voor beide datums, gevolgd door nieuwe plannerblokkades. Het bewijs hieronder komt uit de lokale kopie en is geen bevestigde browsercontrole of actuele productieverbinding.
- **Loggingverbetering: uitsluitend lokaal.** Deze wijziging hoort bij `feature/calendar-release-diagnostics` en is niet naar productie uitgerold. De bevestigde productiecode bleef op commit `735870e43bedcf9fe5fada9e92b49a856b2fb09c`.

## Databasebewijs voor beide februaridatums

Op 18 september is de bestaande lokale kopie van de opgeschoonde productie-export alleen-lezen onderzocht. Er is geen nieuwe import, seeder of kalenderwijziging uitgevoerd. De query koppelt elke uitzondering afzonderlijk aan de exacte kalenderdatum, het type en de auditregels; het aantal van twee uitzonderingen is niet als bewijs op zichzelf gebruikt.

| Datum | Override en vrijgave-audit op 17 september 2026 | Latere blokkade-audit op 17 september 2026 | Huidige lokale blokkade |
| --- | --- | --- | --- |
| `2027-02-22` | `school_vacation`, `released_at=13:29:14`; audit `25`, `calendar_date_released`, `scope=period`, één datum | Audit `27`, `calendar_date_blocked`, `scope=single`, `13:37:05` | `school_vacation`, `source=planner` |
| `2027-02-25` | `school_vacation`, `released_at=13:29:36`; audit `26`, `calendar_date_released`, `scope=period`, één datum | Audit `28`, `calendar_date_blocked`, `scope=single`, `13:37:23` | `school_vacation`, `source=planner` |

Bij beide vrijgave-audits zijn `start_date=end_date=calendar_date`, `affected_count=1`, `type_before=school_vacation` en `type_after=NULL`. De beheerderreferenties bestaan en komen tussen override en audit overeen; de tijdstippen komen tot op de seconde overeen. Er zijn geen namen, contactgegevens, vrije redenen of volledige audit-JSON opgevraagd voor dit bewijs. Tijdstippen hierboven zijn databasewaarden, zonder een daaruit afgeleide browsertijdzone.

De oorspronkelijke `generated`-blokkades zijn niet meer aanwezig. Wel staan voor exact dezelfde datums nieuwe `planner`-blokkades in `disabled_dates`, passend bij de latere audits met `type_before=NULL` en `type_after=school_vacation`. De primaire sleutel op `datum` sluit twee gelijktijdige blokkaderecords voor één datum uit. De auditvlag `manually_blocked_before/after` beschrijft in deze code het type `manual`; een waarde nul bewijst bij een vakantie dus niet dat er geen plannerblokkade is.

**Conclusie:** er is databasebewijs dat de oorspronkelijke vakanties na het schemaherstel zijn vrijgegeven en vervolgens opnieuw door een planner zijn geblokkeerd. De datums zijn in de geïmporteerde toestand nu niet vrij. Een waargenomen browseractie of de actuele productiestatus is niet afzonderlijk bevestigd. De latere plannerblokkades zijn niet door deze onderzoeksrun verwijderd.

## Onderzoeksresultaat lokaal

Beginsituatie van het onderzoek: schone werkboom op `main`, commit `735870e43bedcf9fe5fada9e92b49a856b2fb09c`. Bij de lokale afronding zijn alle bestaande wijzigingen behouden en is vanaf deze commit de featurebranch `feature/calendar-release-diagnostics` aangemaakt. De productiecommit is gelijk aan de uitgangscommit; de lokale loggingwijziging hieronder is nog niet uitgerold.

De originele code is vóór de loggingwijziging getest met PHP 8.3.28 en een afzonderlijke MariaDB 11.4.9 op loopback, met eigen tijdelijke datamap en nieuwe databases. De fixture gebruikt de echte `DisabledDateGenerator`, de seedkolommen uit commit `a5fc7be` en de ingecheckte migraties. Zonder de migratie van 4 augustus ontstaat exact het beschreven verschil:

| Scenario | Zonder override-migratie | Met volledig lokaal schema |
| --- | --- | --- |
| Nieuw dashboardrecord `manual` / `planner` | Vrijgave slaagt | Vrijgave slaagt |
| Nieuw dashboardrecord `school_vacation` / `planner` | Vrijgave slaagt | Vrijgave slaagt |
| Oude seed `school_vacation` / `generated` | `PDOException`, SQLSTATE `42S02`, drivercode `1146` | Vrijgave slaagt, uitzondering en audit worden vastgelegd |

De seeder levert voor de lokale voorbeeldweek 20–28 februari 2027 negen afzonderlijke vakantieblokkades. 22 en 25 februari zijn daarin werkdagen. Het toevoegen van uitsluitend de **bestaande** migratie aan de testdatabase verhelpt dit testscenario. De productie-inspectie heeft dezelfde ontbrekende tabel en dezelfde typen/bronnen voor beide datums bevestigd; de tabel is daar daarna afzonderlijk aangemaakt.

De foutcode correspondeert met een ontbrekende tabel volgens [MariaDB error 1146](https://mariadb.com/docs/server/reference/error-codes/mariadb-error-codes-1100-to-1199/e1146). De logger neemt alleen SQLSTATE en drivercode over uit de exceptionketen; het derde `errorInfo`-element is het databasebericht en wordt weggelaten, conform de [PDO-documentatie](https://www.php.net/manual/en/pdo.errorinfo.php).

De volledige flow in deze checkout is:

1. `DashboardCalendarManagement.vue` selecteert volledige ISO-datums en verstuurt de serverpreview met beide fingerprints; de vrijgave-UI gebruikt `release_period`, ook voor één dag.
2. `dashboardCalendarDateManagementApi.ts` POST JSON met de actiegebonden `X-CSRF-Token` naar `manage-date.php`.
3. De dunne PHP-entrypoint roept `DashboardCalendarDateManagementAction` aan. `SessionGuard`, methode, contenttype en CSRF worden vóór de service gecontroleerd; de DTO weigert onbekende velden.
4. `CalendarDateManagementService` valideert de preview, start een transactie, vergrendelt werkdagen via `booking_day_settings`, leest blokkades/actieve boekingen met `FOR UPDATE` en vergelijkt de fingerprint opnieuw.
5. De repository schrijft uitsluitend voor bron `generated` eerst `generated_disabled_date_release_overrides`. Daarna volgt de exacte delete op `datum`, `type` én `source`.
6. Auditheader en auditdatumregels worden in dezelfde transactie geschreven; pas daarna wordt gecommit. Een fout rolt wijzigingen terug. De auditcode in de bevestigde uitgangscommit verpakt PDO-fouten als `RuntimeException`; de aangeleverde kale `PDOException` past daarom bij een eerdere stap, waaronder het schrijven naar de ontbrekende override-tabel.

## Loggingverbetering en schemaherstel afzonderlijk

De lokale codewijziging past geen kalenderregel of schema aan. Zij herstelt de ontbrekende diagnostiek: operatie, stap, exceptionklasse, oorspronkelijke PDO-klasse/codes en eventueel request-ID. Ook fouten bij de eerste preview en een eventuele rollback worden afgevangen. `UNIQUE_ID` (indien aanwezig) of anders `X-Request-ID` wordt alleen geaccepteerd bij 1–128 toegestane ASCII-tekens en teruggegeven als `X-Request-ID`-responseheader. Er worden geen IDs gegenereerd als infrastructuur/request er geen aanlevert. De browsertekst blijft algemeen.

- **Logging:** de lokale PHP-wijzigingen en regressietests blijven een afzonderlijke, niet uitgerolde wijziging. Zij maken fouten herkenbaar en maken de ontbrekende tabel niet aan.
- **Schemaherstel:** de beheerder heeft de reeds aanwezige migratie van 4 augustus ongewijzigd op productie uitgevoerd. In de lokale code-diff is die migratie ongewijzigd. Het lokale succes na deze migratie vereiste geen loggingwijziging; schemaherstel is dus niet afhankelijk van het uitrollen van de diagnostiek.
- **Dataherstel:** er is geen bewijs dat blokkaderecords of auditdata via een extra migratie moeten worden aangepast. De uitgevoerde schemamigratie maakt alleen de ontbrekende tabel aan. Vrijgave blijft een dashboardactie met audittrail; er is geen tweede migratie gemaakt.

## Gewone lokale ontwikkelomgeving

De oorspronkelijke lokale database miste de override-tabel eveneens. Daarna heeft de gebruiker afzonderlijk toestemming gegeven om `geoform_db` door de opgeschoonde productie-export te vervangen. Die overname is voltooid; de eerdere instructie om de ontbrekende tabel lokaal aan te maken is daardoor vervallen. De controle hieronder betreft de gewone ontwikkeldatabase, niet een disposable testdatabase.

| Onderdeel | Lokaal gecontroleerd |
| --- | --- |
| Applicatieomgeving | `development` |
| Doel | `127.0.0.1:3306`, database `geoform_db` |
| Server | MySQL `8.4.7` |
| Gegevens | 17 tabellen, 151 aanvragen; gelijk aan de behouden controledatabase |
| Override-tabel | Aanwezig, met de twee hierboven afzonderlijk gecontroleerde uitzonderingen |
| Laatste afronding | Alleen-lezen inspectie en volledige back-up; geen nieuwe import of muterende applicatiecontrole |

De huidige lokale toestand is op 18 september volledig geback-upt, inclusief eventuele routines, triggers en events. Gzip-integriteit, exacte dumpaantallen per tabel en ongewijzigde tabelinhoud vóór/na de back-up zijn gecontroleerd. Deze back-up is expliciet gelabeld als **NA de overname** en staat met het operationele verslag en controle-uitvoer buiten Git. De oude lokale back-ups met 146 aanvragen zijn na het door de gebruiker opruimen van Downloads niet teruggevonden; de eenmalige prullenbakcontrole vond nul items. Dat onderzoek is afgesloten. De teruggevonden productie-export en de nieuwe lokale back-up herstellen die oude lokale toestand niet.

De bestaande lokale mailbeveiliging is behouden: `MAIL_HOST=127.0.0.1`, `MAIL_PORT=1`, zonder listener op die poort. De eerder ingestelde `SET PERSIST event_scheduler=OFF` geldt voor de **hele lokale MySQL-server**, inclusief andere databases. De actuele en persistente waarde zijn gecontroleerd; deze afronding heeft de instelling niet gewijzigd. Lokale configuratie, dumps, credentials en persoonsgegevens worden niet in deze commit opgenomen.

## Definitieve diff en verificatie

De volledige **loggingdiff** is beoordeeld: uitsluitend zes PHP-bestanden voor gerichte diagnostiek, twee testbestanden en dit document. De mutatiequeries, type-/bronbeleid, fingerprintcontroles, autorisatie, CSRF en auditinhoud zijn behouden. De wijziging bevat geen runtimefallback, automatische schemawijziging of aangepaste kalenderregels. De extra afhandeling van preview- en rollbackfouten dient de diagnostiek en de generieke foutrespons. De bestaande migratie heeft geen diff met de uitgangscommit. Vue/TypeScript, publieke boekingslogica, mail, catering, prijzen, roosters en voorwaarden zijn door deze loggingwijziging niet aangepast.

Bij de laatste werkboomcontrole verscheen daarnaast een verwijdering van `public/assets/booking/roosters/images/po_skd_6.png`, die aan het begin van deze afronding niet aanwezig was. De gebruiker heeft bevestigd dat dit een bedoelde wijziging is: de naamgeving was fout en het juiste rooster is al aanwezig. Deze verwijdering is behouden en blijft buiten de loggingdiff, zodat zij afzonderlijk kan worden meegenomen in het gewenste vervolgwerk.

De kalenderdiagnostiek omvat uitsluitend de negen hieronder genoemde bestanden. De verwijderde roosterafbeelding is een afzonderlijke wijziging in publieke boekingsassets, zonder relatie met de kalenderdiagnostiek, en blijft buiten deze commit. De reeds bestaande migratie van 4 augustus is ongewijzigd.

Gewijzigde/toegevoegde bestanden:

- Gewijzigd — `public/api/admin/calendar/manage-date.php`: bestaand request-ID doorgeven.
- Gewijzigd — `src/Services/Http/Api/Admin/DashboardCalendarDateManagementAction.php`: request-ID veilig koppelen aan response en logging.
- Gewijzigd — `src/Services/Dashboard/Calendar/CalendarDateManagementService.php`: foutstappen, veilige logging en rollbackfoutafhandeling.
- Nieuw — `src/Services/Dashboard/Calendar/CalendarDateManagementFailureLogger.php`: begrensde technische foutcontext, inclusief geneste PDO-fouten.
- Gewijzigd — `src/Services/Sql/CalendarDateManagementSqlRepository.php`: onderscheiden van override, delete, auditheader en auditdatumfouten.
- Nieuw — `src/Services/Sql/CalendarDateManagementSqlException.php`: opslagstap bewaren naast de oorspronkelijke exception.
- Nieuw — `scripts/tests/CalendarDateReleaseMariaDbIntegrationTest.php`: reproductie en regressies in een nieuw aangemaakte lokale disposable database.
- Nieuw — `scripts/tests/CalendarDateManagementFailureLoggingTest.php`: veilige foutvelden en afwijzen van onveilige context.
- Nieuw — `docs/calendar-release-production-inspection.md`: dit onderzoeksverslag en de servercontroles.

Uitgevoerde verificatie, opnieuw geslaagd bij de definitieve lokale afronding op 17 september 2026:

- De lokale tests draaiden op PHP `8.3.28` en MariaDB `11.4.9`; productie gebruikt volgens de inspectie MariaDB `10.11.14`. De tests zijn niet op die exacte databaseversie of op productie uitgevoerd.
- Vóór codewijzigingen: 35 MariaDB-controles, inclusief hetzelfde kale logpatroon als in het incident.
- Na wijzigingen: 127 MariaDB/controllercontroles. Beide plannertypen, oude seed na provenance-migratie, ontbrekende override-tabel, single/periode, idempotentie, opnieuw seeden, weekenden, rollback na een deletefout, na een audit-FK-fout en na een latere auditchildfout slagen. Ook initiële previewfouten, sessie/CSRF/methode-afwijzing, generieke HTTP 500 en veilige request-ID-koppeling zijn getest.
- `CalendarDateManagementFailureLoggingTest.php`: 16 controles geslaagd.
- Bestaande `CalendarDateManagementMariaDbIntegrationTest.php`: geslaagd in een tweede nieuwe disposable database met de echte kalendermigraties; ook boekingen, conflictdetectie en bestaande kalenderregels zijn gecontroleerd.
- Bestaande domain-, HTTP- en frontendcontracttests voor kalenderdatumbeheer: geslaagd. Geen Vue/TypeScript-wijzigingen.
- PHP 8.3-syntaxcontrole van alle acht gewijzigde/toegevoegde PHP-bestanden en `git diff --check`: geslaagd.

Bij de commitvoorbereiding op 18 september zijn de volledige runtime- en testdiff opnieuw beoordeeld. Zij komen overeen met de eerder geteste loggingpatch. Exceptionketens behouden de oorspronkelijke PDO-codes, opslagstappen onderscheiden override/delete/audit, en rollbackfouten krijgen eigen veilige diagnostiek. Request-ID's zijn begrensd en gevalideerd voordat ze in logs en responseheaders komen. Autorisatie en actiegebonden CSRF blijven vóór de mutatie staan; er is geen wijziging aan kalenderregels of transactiegrenzen.

De acht PHP-bestanden zijn opnieuw met PHP `8.3.28` op syntax gecontroleerd. De loggingtest (16 controles), domain-, HTTP- en frontendcontracttests zijn opnieuw geslaagd, evenals `git diff --check`. De code van de eerder geslaagde MariaDB-regressies is ongewijzigd; deze muterende tests zijn daarom niet onnodig herhaald. Er zijn in deze afronding geen tests tegen de geïmporteerde `geoform_db` uitgevoerd.

Reproduceer de nieuwe tests lokaal, met uitsluitend een lokale MariaDB waarop nieuwe testdatabases mogen worden aangemaakt. Gebruik de poort van die lokale testserver (tijdens dit onderzoek `33317`). De test laadt geen `.env` of applicatiebootstrap en weigert een remote host of `APP_ENV=production`:

```powershell
$env:STATUS_TEST_DB_CONFIRM = 'YES_DISPOSABLE'
$env:STATUS_TEST_DB_HOST = '127.0.0.1'
$env:STATUS_TEST_DB_PORT = '33317'
$env:STATUS_TEST_DB_USER = 'root'
php scripts/tests/CalendarDateReleaseMariaDbIntegrationTest.php
php scripts/tests/CalendarDateManagementFailureLoggingTest.php
php scripts/tests/CalendarDateManagementDomainTest.php
php scripts/tests/CalendarDateManagementHttpContractTest.php
php scripts/tests/CalendarDateManagementFrontendContractTest.php
```

Gebruik indien nodig `STATUS_TEST_DB_PASSWORD` voor uitsluitend lokale testcredentials. De disposable database wordt in `finally` opgeruimd. De MariaDB-verificatie van 17 september gebruikte uitsluitend een aparte lokale instantie op poort `33317` met een eigen tijdelijke datamap en nieuwe databases. Na afloop is gecontroleerd dat de testdatabases weg waren, is de instantie afgesloten en zijn de tijdelijke datamap en inspectiehulpscripts verwijderd. Er zijn geen productie-integratietests, productiedatamutaties, mails, pushes of deployments uitgevoerd. Het door de gebruiker uitgevoerde productie-schemaherstel en de afzonderlijk geautoriseerde lokale database-overname staan los van de loggingcommit.

## Read-only controles op productie

De inspectie en het schemaherstel zijn afgerond zoals bovenaan beschreven. Onderstaand protocol blijft als referentie behouden; er wordt niet gevraagd de volledige inspectie te herhalen. De nog ontbrekende browsercontrole moet rekening houden met de bewezen latere plannerblokkades. Deze onderzoeksrun voert geen nieuwe vrijgave uit.

Voer eventuele aanvullende controles uit vanuit de **werkelijk door PHP-FPM gebruikte release** en met een bestaande, bij voorkeur read-only databaseverbinding naar de onderwijsapp. Geen `.env`, volledige database-export, sessies, requestbodies of persoonsgegevens delen. Dit protocol schrijft geen applicatiedata en voert geen seeder, migratie of vrijgave uit. De door de beheerder uitgevoerde schemamigratie staat los van dit protocol.

## 1. Release en jaartal vaststellen

In de actieve applicatiedirectory op de server:

```sh
git rev-parse HEAD
git status --short --branch
sha256sum public/api/admin/calendar/manage-date.php \
  src/Services/Http/Api/Admin/DashboardCalendarDateManagementAction.php \
  src/Services/Dashboard/Calendar/CalendarDateManagementService.php \
  src/Services/Sql/CalendarDateManagementSqlRepository.php \
  src/Services/Sql/DisabledDatesSqlService.php \
  src/Dashboard/Calendar/CalendarDateManagementPolicy.php \
  src/Booking/SchoolVacationConfig.php \
  database/sql/2026-07-30_add_disabled_date_provenance_and_audit_types.sql \
  database/sql/2026-08-04_create_generated_disabled_date_release_overrides.sql
```

Ontbreekt `.git`, deel dan alleen de beschikbare bestandschecksums en het release-ID uit het deploymentoverzicht. CLI-PHP bewijst niet welke PHP-versie/configuratie FPM gebruikt; voor het incident is PHP 8.3-FPM gemeld.

De datumselectie en beide productie-recordtypen/bronnen zijn inmiddels teruggekoppeld. Bij een latere functionele controle zijn uitsluitend de betrokken ISO-datums, actie, uitkomst en eventueel request-ID relevant voor het verslag. Geen tokens, volledige body of HAR delen.

Voer in de geselecteerde applicatiedatabase uit:

```sql
SELECT VERSION() AS database_version, @@SESSION.sql_mode AS sql_mode,
       @@SESSION.time_zone AS session_time_zone, @@GLOBAL.time_zone AS global_time_zone;

SELECT TABLE_NAME, ENGINE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('disabled_dates', 'generated_disabled_date_release_overrides',
                    'booking_day_settings', 'calendar_date_change_history',
                    'calendar_date_change_history_dates')
ORDER BY TABLE_NAME;

SHOW CREATE TABLE disabled_dates;

-- Oorspronkelijke inventaris over alle jaren; de onderzochte records zijn inmiddels voor 2027 bevestigd.
SELECT datum, DAYOFWEEK(datum) AS weekday_number, type,
       reden IS NULL AS reason_is_null, CHAR_LENGTH(reden) AS reason_length
FROM disabled_dates
WHERE MONTH(datum) = 2 AND DAYOFMONTH(datum) IN (22, 25)
ORDER BY datum;
```

Deel ook een eventuele foutcode van een aanvullende inspectiequery, zonder ongefilterde foutmelding. De eerder ontbrekende override-tabel is inmiddels door de beheerder aangemaakt en gecontroleerd.

## 2. Tabelstructuur en betrokken records

Deze tabellen bestaan na het teruggekoppelde schemaherstel. De override-tabel is al met de migratie vergeleken; onderstaande queries blijven beschikbaar voor eventuele aanvullende inspectie:

```sql
SHOW CREATE TABLE generated_disabled_date_release_overrides;
SHOW CREATE TABLE booking_day_settings;
SHOW CREATE TABLE calendar_date_change_history;
SHOW CREATE TABLE calendar_date_change_history_dates;

-- Extra productieconstraints op de mutatietabellen, ook inkomende relaties.
SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME,
       REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE CONSTRAINT_SCHEMA = DATABASE()
  AND (TABLE_NAME IN ('disabled_dates', 'generated_disabled_date_release_overrides',
                      'booking_day_settings', 'calendar_date_change_history',
                      'calendar_date_change_history_dates')
       OR REFERENCED_TABLE_NAME IN ('disabled_dates', 'generated_disabled_date_release_overrides',
                                   'booking_day_settings', 'calendar_date_change_history',
                                   'calendar_date_change_history_dates'))
ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION;

SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = DATABASE()
  AND EVENT_OBJECT_TABLE IN ('disabled_dates', 'generated_disabled_date_release_overrides',
                            'booking_day_settings', 'calendar_date_change_history',
                            'calendar_date_change_history_dates')
ORDER BY EVENT_OBJECT_TABLE, TRIGGER_NAME;
```

Onderstaande aanvullende selecties gebruiken de week rond de bevestigde records in februari 2027. De omliggende week toont ook de dekking door andere dagen uit dezelfde vakantie. `source=generated` voor de oorspronkelijke probleemrecords en de primaire sleutel op `datum` zijn bevestigd; een duplicaatcontrole is daardoor niet nodig. Voer queries met audittypen alleen uit als de bijbehorende kolommen zijn bevestigd. De override-query laat de persistente uitzondering zien; controleer daarnaast steeds actuele blokkades en latere auditregels.

```sql
SELECT datum, type, source, reden IS NULL AS reason_is_null,
       CHAR_LENGTH(reden) AS reason_length
FROM disabled_dates
WHERE datum BETWEEN '2027-02-20' AND '2027-02-28'
ORDER BY datum;

-- Schemaherstel is afgerond; direct na aanmaken bevatte deze tabel 0 records.
SELECT o.datum, o.type, o.released_by_admin_id, o.released_at,
       a.id IS NOT NULL AS releasing_admin_exists
FROM generated_disabled_date_release_overrides o
LEFT JOIN admin_users a ON a.id = o.released_by_admin_id
WHERE o.datum BETWEEN '2027-02-20' AND '2027-02-28'
ORDER BY o.datum, o.type;

SELECT visit_date, max_schools_override, max_students_override
FROM booking_day_settings
WHERE visit_date BETWEEN '2027-02-20' AND '2027-02-28'
ORDER BY visit_date;

-- Alleen technische auditvelden; geen reason, summary_json of adminprofielen.
SELECT h.id, h.action, h.scope, h.start_date, h.end_date, h.affected_count,
       h.changed_by_admin_id, h.created_at, a.id IS NOT NULL AS changing_admin_exists
FROM calendar_date_change_history h
LEFT JOIN admin_users a ON a.id = h.changed_by_admin_id
WHERE h.start_date <= '2027-02-28' AND h.end_date >= '2027-02-20'
ORDER BY h.id DESC
LIMIT 50;

SELECT history_id, calendar_date, manually_blocked_before, manually_blocked_after,
       type_before, type_after, reason_before IS NULL AS reason_before_is_null,
       CHAR_LENGTH(reason_before) AS reason_before_length,
       reason_after IS NULL AS reason_after_is_null,
       CHAR_LENGTH(reason_after) AS reason_after_length
FROM calendar_date_change_history_dates
WHERE calendar_date BETWEEN '2027-02-20' AND '2027-02-28'
ORDER BY history_id DESC, calendar_date
LIMIT 100;
```

Vergelijk desgewenst één reeds bekende succesvolle handmatige vrijgave (`anders`) en één (`vakantie`) met dezelfde gerichte auditselecties voor hun bevestigde datums. Een vrijgegeven record hoort niet meer in `disabled_dates` te staan. Ontbrekende oude auditregels bewijzen geen import: de seeder schrijft geen planner-audit en mislukte transacties rollen audit terug.

## 3. Betekenis en vervolgstap

- De lokale migratie van 30 juli geeft bestaande `manual`-records bron `planner`, alle andere bron `generated`. Er is geen oorspronkelijke aanmaakdatum of import-ID in de gevonden kalendercode. De boekingsimport schrijft geen kalenderblokkades.
- Alleen een gegenereerde `school_vacation` schrijft bij vrijgave eerst een persistente uitzondering. Een handmatig aangemaakte vakantie heeft bron `planner` en slaat deze stap over.
- De lokale migratie van 4 augustus maakt `generated_disabled_date_release_overrides` met primaire sleutel `(datum, type)`. Er staat in die migratie geen foreign key naar `disabled_dates` of `admin_users`. Eventuele extra productieconstraints of ontbrekende rechten vereisen afzonderlijke beoordeling.
- De seeder vouwt vakantieperiodes uit naar losse dagen en voegt overlappingen per datum samen. De primaire sleutel op `disabled_dates.datum` is nu ook op productie bevestigd: meerdere blokkaderecords voor exact dezelfde datum zijn daardoor uitgesloten. Auditperiodes zijn historie, geen zelfstandige blokkadelaag.
- Productie: beide bronnen zijn `generated`; de bestaande migratie is toegepast, de tabeldefinitie gecontroleerd en de back-up technisch gecontroleerd zoals bovenaan vastgelegd. Het schemaherstel is afgerond zonder SQL-delete van blokkades.
- Functionele productiecontrole: de databasekopie bewijst beide eerdere vrijgaven én latere plannerblokkades. Een bevestigde browsercontrole ontbreekt nog. Controleer daarbij de bedoelde actuele plannerstatus; voer geen extra vrijgave uit uitsluitend om een eerdere audit te bevestigen.
- Gewone lokale ontwikkeling: `geoform_db` bevat inmiddels de opgeschoonde productiegegevens en de override-tabel. De huidige toestand en de controledatabase zijn behouden. Er is een geverifieerde back-up na de overname buiten Git; de oude lokale back-up is niet teruggevonden en dat onderzoek is afgesloten.
- Logging: de diagnostiek wordt als afzonderlijke lokale wijziging op `feature/calendar-release-diagnostics` vastgelegd. Voor productie zijn nog de gebruikelijke review en expliciet geautoriseerde publicatie/uitrol nodig. De bestaande schemamigratie hoeft niet opnieuw te worden uitgevoerd. Bij een later geautoriseerd beheerincident zijn alleen operatie, stap, exceptionklasse, SQLSTATE, drivercode en eventueel request-ID nodig voor technische terugkoppeling.

De `SessionGuard`-meldingen bij GET-verzoeken blijven een afzonderlijk signaal: de lokale mutatiecontroller weigert ongeldige sessies vóór de service wordt aangeroepen.
