# V1 Ronde C — centrale personeelscatalogus

De dashboardroute /roosters/personeel beheert weergavenaam, actief/inactief, betaald/vrijwilliger, begeleiden, koken en modulevaardigheden met voorkeursrangen 1–255. Sinds de [UI-verfijning](personeelsbeheer-ui-verfijning.md) zijn nieuwe en inhoudelijk gewijzigde voorkeuren uniek per medewerker. Bestaande dubbele rangen blijven exact behouden zolang de voorkeuren zelf niet veranderen. Rollen staan los van vaardigheden: het uitzetten van begeleiden verwijdert geen vaardigheden. Er zijn geen nieuwe HR-, contact- of tariefvelden.

## Identiteit en historie

Personeels-ID is de blijvende technische identiteit. Een opgeslagen kokkeuze gebruikt dat ID en actuele bevoegdheden. Bij een nog lege kokkeuze moet de planner expliciet kiezen; er is geen standaardkok op basis van een naam.

De database blokkeert iedere fysieke personeelsverwijdering. Deactiveren is de beheerhandeling. Bestaande concepttoewijzingen blijven staan, met zichtbare waarschuwingen. Inactieve medewerkers verschijnen niet in nieuwe keuzelijsten; een bestaande ongeldige keuze blijft zichtbaar tot expliciete verwijdering/vervanging. Finalisatie controleert opnieuw tegen de actuele catalogus. Ook een inactieve medewerker die alleen in de dagselectie staat blokkeert finalisatie.

Gepubliceerde roosters gebruiken hun immutable snapshot. Personeelswijzigingen passen snapshots, presentatie, bestaande PDF-bytes of hashes niet aan. Gepubliceerde snapshots worden niet opnieuw tegen de actuele personeelscatalogus gevalideerd. Heropenen maakt de actuele concepttoewijzingen weer controleerbaar.

## Gevolgen vóór opslag

Bestaande medewerkers worden in twee stappen gewijzigd:

1. POST preview-impact valideert de gewenste wijziging, versies en huidige referenties. De reactie vermeldt geraakte conceptrooster-ID, bezoekdatum, roosterrevisie en concrete redenen.
2. POST update controleert alles opnieuw en accepteert alleen de getekende bevestiging. Bij impact toont de UI eerst de gevolgen en vraagt een expliciete bevestiging.

Deactiveren, verlies van begeleiden/koken en verwijdering van een gebruikte vaardigheid tellen mee. Finale roosters vallen buiten de impactquery. Een nieuwe toewijzing of heropening tussen controle en opslag maakt de bevestiging ongeldig.

Het bevestigingstoken is vijf minuten geldig, HMAC-getekend met een uitsluitend server-side sessiegeheim en gebonden aan beheerder, medewerker-ID/versie, catalogusrevisie, bestaande/nieuwe waarden, expliciete legacyverwijderingen, moduleconfiguratie en geraakte conceptrevisies. Het CSRF-token wordt niet als tekensleutel gebruikt.

Alle catalogusmutaties en roostertransacties die personeel, conceptreferenties of lifecycle wijzigen nemen eerst dezelfde cataloguslock. Daarna blijft de bestaande volgorde dag → rooster → boeking gelden waar deze locks nodig zijn. De globale cataloguslock kiest correctheid boven maximale gelijktijdigheid; bij deze kleine catalogus is dat een beperkte, gedocumenteerde afweging.

De generator retourneert catalogRevision; de HTTP-apply vereist expectedCatalogRevision. Wijzigingen sinds het voorstel geven 409. De backend leest actuele vaardigheden opnieuw. De dagselectie heeft een afzonderlijke versiegebonden mutatie; personeel met een resterende les- of koktoewijzing kan daar niet stil worden verwijderd.

## Legacyvaardigheden

De actieve modulekeys komen uit BookingProgramConfig::MODULES. MODULE_LABELS alleen is geen actieve catalogus.

Bestaande onbekende keys verschijnen als legacy/onbekend. De normale preferences-invoer beschrijft uitsluitend actieve modules. De server voegt bestaande legacyvaardigheden met hun oorspronkelijke rang toe. De expliciete legacyPreferenceRanks-invoer kan de rang van een eigen, bestaande en behouden legacyvaardigheid wijzigen. Alleen removeLegacyModuleKeys kan bewust een bestaande onbekende key verwijderen; dit valt onder dezelfde impactcontrole en audit.

Een nieuwe actieve key die door de databasecollatie botst met een anders gespelde legacykey wordt geweigerd. Er is geen upsert die zo ongemerkt de legacyrang of key overschrijft.

## API en beveiliging

| Route onder /api/admin/rosters | Methode | Doel / CSRF-scope |
|---|---|---|
| staff/index.php | GET | Catalogus, actieve moduleopties, catalogRevision; alleen lezen |
| staff/show.php?staffId=… | GET | Medewerker, versie, laatste 50 auditregels; alleen lezen |
| staff/create.php | POST | Toevoegen; create-roster-staff |
| staff/preview-impact.php | POST | Gevolgen controleren; preview-roster-staff-impact |
| staff/update.php | POST | Bevestigde wijziging; update-roster-staff |
| staff/deactivate.php | POST | Bevestigd deactiveren; deactivate-roster-staff |
| staff-selection-save.php | POST | Expliciete dagselectie; update-roster-staff-selection |

Alle routes vereisen private dashboardauth. Mutaties vereisen de eigen CSRF-header X-CSRF-Token, JSON en strikt getypeerde input. Controllers bevatten geen SQL; repositories gebruiken prepared parameters. Naam en labels blijven gewone tekst en Vue escapeert output.

Create gebruikt expectedCatalogRevision; update/preview bovendien staffId en expectedVersion. Onbekende invoervelden worden afgewezen. Een ongewijzigde update schrijft geen audit/revisie. Een werkelijke wijziging verhoogt versie en catalogusrevisie en schrijft één auditregel in dezelfde transactie. Conflicten behouden de formulierinvoer. Er is geen delete-endpoint.

Audit bewaart beheerder-ID, tijdstip UTC, actie, oude/nieuwe versie, oude/nieuwe toegestane personeelswaarden/vaardigheden en bevestigde impact. Audit heeft immutable UPDATE/DELETE-triggers en RESTRICT-foreign keys. Bestaande notes en kostenvelden worden niet bewerkt of aan de audit toegevoegd.

## Schema en eenmalige bootstrap

Nieuwe schema-upgrade: database/sql/2026-10-05_add_roster_staff_management.sql.

Deze voegt toe:

- roster_staff_members.version met minimum 1 en controles op status/rollen/dienstverband;
- een rangcontrole op roster_staff_module_preferences;
- singleton roster_staff_catalog_state met revision en bootstrapregistratie;
- append-only roster_staff_change_history, unieke (staff_id, new_version), FK naar personeel/admin;
- roster_staff_no_delete, roster_staff_history_no_update, roster_staff_history_no_delete.

De historische SQL-bestanden 2026-09-22_create_roster_staff_catalog.sql en 2026-09-22_extend_roster_staff_roles.sql bevatten voortaan alleen schema. De oude personeels-INSERT/UPDATE/ON DUPLICATE KEY UPDATE is verwijderd. Ook de historische rollenrollback verwijdert geen personeel meer op naam. Die rollback wordt niet gebruikt bij Ronde C.

De goedgekeurde startcatalogus staat in database/seeds/roster_staff_v1.json: exact 20 medewerkers, 86 vaardigheden, vaste initiële IDs 1–20. De onveranderde oorspronkelijke seed-SQL staat uitsluitend als onafhankelijke testreferentie in scripts/tests/fixtures/roster-staff-approved-v1.sql; dit is geen deploymentbestand.

### Verse installatie

Installeer de eerdere vereiste schema's in hun normale volgorde, inclusief personeelsrollen en de V1-lifecycle/snapshotstructuur. Daarna kan de Ronde-C-upgrade met expliciete fresh-install worden uitgevoerd. Alleen bij een lege personeelscatalogus én afwezigheid van bestaande roosters wordt de startcatalogus in één transactie ingevoerd en als seeded geregistreerd.

### Bestaande operationele installatie

Voer eerdere migraties/seeds niet opnieuw uit. Maak een volledige schema/data-backup en controleer doel, grootte en SHA-256. Voer uitsluitend de nieuwe Ronde-C-upgrade uit. Zonder fresh-install is de bestaande catalogus altijd leidend, ook als deze leeg of afwijkend is. Er worden geen seedmedewerkers of vaardigheden toegevoegd. De registratie wordt adopted_existing.

Een niet-lege afwijkende catalogus wordt ook met fresh-install nooit aangevuld. Zodra de bootstrap geregistreerd is, is iedere latere uitvoering een no-op, ook als iemand alsnog fresh-install opgeeft of het seedbestand later wijzigt.

### Expliciete CLI

scripts/apply-roster-staff-management-migration.php gebruikt alleen de bestaande lokale/omgevingsconfiguratie. Standaard doet het uitsluitend een preflight:

    php scripts/apply-roster-staff-management-migration.php --database=EXACT_CONFIGURED_DATABASE

Uitvoeren vereist --execute plus --backup-file en --backup-sha256 van een gecontroleerde volledige backup. Alleen een werkelijk verse installatie gebruikt tevens --fresh-install. Een gedeeltelijk aanwezig schema geeft een fout; er volgt geen automatische repair, replay of rollback. DDL is niet transactioneel terug te draaien. Bij een fout: stoppen, de fout en bereikte schemastand beoordelen.

Deze ronde voert niets uit op productie/staging en doet geen deployment. Deze instructies beschrijven de latere expliciete beheerhandeling.

## Verificatie

Nieuwe tests dekken DTO's, CRUD/status/rollen/rangen, lees-only HTTP, eigen CSRF, getekende impact, bestaande en nieuwe conceptreferenties, gelijktijdige edits/finalisatie/heropening, expliciete dagselectie, gewijzigde optimizer-skills, legacybehoud/collatieconflict, immutable audit/personeel, kokhernoeming en onveranderlijke snapshots/PDF-bytes.

Seedtests vergelijken iedere initiële ID, naam, status, employment type, rol, key en rang met de onafhankelijke oorspronkelijke SQL-uitvoer. Voor bestaande en afwijkende catalogi vergelijken ze alle bestaande waarden en timestamps vóór/na, plus een herhaalde no-op. Migratietests controleren afwezig/volledig/gedeeltelijk schema en weigeren replay van gedeeltelijke wijzigingen.

De echte Vue-browsertest gebruikt een uitsluitend lokale synthetische HTTP-server. Database- en concurrencytests gebruiken uitsluitend lokale disposable databases. Operationele persoonsgegevens worden niet als test gewijzigd. De runmap bevat RAPPORT.md, nulmeting, backups, testlogs, vergelijkings-JSON en screenshots.
