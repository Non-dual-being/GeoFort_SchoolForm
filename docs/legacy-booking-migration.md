# Legacy-aanvragen migreren

Deze migratie is append-only. Voer haar alleen uit na een geteste backup en in een schrijfstop voor bron en doel. Het script vereist PHP 8.1 of hoger. Wanneer de standaard-CLI ouder is, moet `MIGRATION_PHP_BINARY` expliciet naar een uitvoerbare PHP 8.1+-binary wijzen. Het script valideert die versie en staat maximaal één herstart toe; er is geen machinespecifieke fallback.

## Configuratie

De bron gebruikt uitsluitend `LEGACY_DB_HOST`, `LEGACY_DB_PORT`, `LEGACY_DB_NAME`, `LEGACY_DB_USER` en `LEGACY_DB_PASSWORD`. Geef deze gebruiker alleen `SELECT` op de legacybron. Zet `LEGACY_SOURCE_FILENAME` op de gecontroleerde dumpnaam; alleen de bestandsnaam wordt in de import-run bewaard.

Het doel gebruikt `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` en `DB_PASSWORD`. Bron en doel mogen niet dezelfde host/poort/schemanaam-combinatie zijn. Gebruik nooit productiegegevens in lokale configuratie.

## Databasevrije audit

```bash
php scripts/migrate-legacy-bookings.php --audit-dump=local-backups/<dumpbestand>.sql
```

Audit opent geen database en toont alleen totalen, technische codes en legacy-ID's. Los blokkerende fouten op en beoordeel waarschuwingen en potentiële dubbelgroepen. Waarschuwingen geven exitcode 0; mapperfouten geven een niet-nul exitcode.

De parser ondersteunt specifiek een HeidiSQL-export met precies één expliciet `INSERT INTO aanvragen (kolommen...) VALUES (...)`-blok. MySQL-backslashescapes, gequote tekst, `NULL`, komma's, puntkomma's, haakjes en multiline tekst worden ondersteund. Meerdere actuele `aanvragen`-INSERT-blokken blokkeren bewust; voeg die dumps niet handmatig samen zonder nieuwe regressietest.

## Read-only dry-run

```bash
php scripts/migrate-legacy-bookings.php --dry-run
```

Dry-run leest bron en doel, vergelijkt de unieke herkomstsleutel en rapporteert invoegen, identiek overslaan en gewijzigd blokkeren. Er wordt geen transactie gestart en geen write-statement uitgevoerd. Gebruik voor extra zekerheid een doelaccount met alleen `SELECT`.

## Execute

```bash
php scripts/migrate-legacy-bookings.php --execute
```

Execute vereist vooraf uitvoering van `database/sql/2026-07-15_add_legacy_booking_import_tracking.sql`. De DDL is niet transactioneel; volg de preflight- en hervatinstructies in het bestand. Kolommen, indexen en foreign keys worden portable via `information_schema` gecontroleerd en alleen bij ontbreken met dynamische SQL toegevoegd; er wordt geen engine-specifieke `ADD ... IF NOT EXISTS` gebruikt. Alle daaropvolgende importwrites vallen wel in één doeltransactie. Nieuwe aanvraag-ID's worden door MySQL gegenereerd; selecties krijgen die ID. Identieke herhalingen worden overgeslagen. Een gewijzigde bronchecksum blokkeert de gehele run.

Voor commit controleert het script de aantallen van de import-run, selecties, orphan-records en dubbele herkomstsleutels. Fingerprints bewaken dat bestaande niet-legacy-aanvragen en `form_submit_log` niet wijzigen.

## Veilige lokale workflow

1. Maak en verifieer een volledige backup van het doel.
2. Importeer de legacydump in een afzonderlijk lokaal bronschema.
3. Voer databasevrije audit uit.
4. Configureer een read-only bronaccount en een afzonderlijk doel.
5. Voer dry-run uit en beoordeel alle codes en legacy-ID's.
6. Pas het tracking-SQL-bestand toe op een wegwerpbare doelkloon.
7. Voer execute uit en controleer dashboard, totalen, foreign keys en een nieuwe formulierinzending.
8. Test rollback per import-run en herstel vanuit backup op de kloon.

## Veilige productieworkflow

1. Plan een maintenancevenster en stop writes naar beide formulieren.
2. Maak een herstelbare productiebackup en noteer vooraf aantallen.
3. Deploy vooraf lokaal gevalideerde code en pas het tracking-SQL-bestand toe.
4. Voer audit en dry-run uit met expliciete productieconfiguratie.
5. Voer execute eenmaal uit en controleer importtotalen, dashboard en publiek formulier.
6. Herhaal dry-run: alle geïmporteerde records moeten nu als identiek overgeslagen worden.
7. Hervat writes pas na functionele goedkeuring.

## Rollback per import-run

Een fout tijdens execute veroorzaakt automatisch transactie-rollback. Controleer een gerichte rollback eerst read-only:

```bash
php scripts/rollback-legacy-booking-import.php --run-id=<id>
```

Voer hem daarna expliciet uit met `--execute`. De tool verwijdert in één transactie uitsluitend aanvragen met de gekozen run én `source_system=legacy-school-db`; selecties volgen via `ON DELETE CASCADE`. Fingerprints bewaken niet-legacy aanvragen en `form_submit_log`.

Het afzonderlijke bestand `database/sql/2026-07-15_rollback_legacy_booking_import_tracking.sql` blokkeert zolang enige aanvraag een `source_system` heeft. Rollback dus eerst iedere import-run. Voer tracking-rollback niet uit als herkomstinformatie behouden moet blijven.

## Optionele MariaDB-integratietest

`scripts/tests/LegacyBookingMariaDbIntegrationTest.php` draait alleen met alle `MIGRATION_TEST_DB_*`-variabelen en `MIGRATION_TEST_DB_CONFIRM=YES_DISPOSABLE`. De schemanaam moet duidelijk `test`, `tmp`, `scratch` of `throwaway` bevatten en `APP_ENV` mag niet `production` zijn. De test is destructief binnen dat ene wegwerpschema en mag nooit naar een normale lokale of productieomgeving wijzen.
