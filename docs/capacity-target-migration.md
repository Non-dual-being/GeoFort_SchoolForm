# Migratie organisatietargets

De migratie gebruikt bewust geen `USE`-statement. De applicatie- of deployconfiguratie bepaalt de database. De tabel vereist MySQL 8.0.16 of nieuwer, of MariaDB 10.2.1 of nieuwer, zodat de grenzen via afgedwongen `CHECK`-constraints worden bewaakt.

## Via phpMyAdmin

1. Selecteer links eerst de database die bij `DB_NAME` hoort.
2. Voer `SELECT DATABASE();` uit en controleer dat de uitkomst de bedoelde database is.
3. Importeer `database/sql/2026-08-07_create_capacity_targets.sql` en voer het bestand uit.
4. Controleer daarna met `SHOW CREATE TABLE capacity_targets;` de kolommen, foreign keys en beide `CHECK`-constraints.
5. Voer vanuit de projectmap `php scripts/apply-capacity-targets-migration.php` uit om ook een al bestaande tabel expliciet te valideren.

Een statische phpMyAdmin-melding bij `CHECK` is een melding van diens SQL-parser. De uitvoerfout `#1046 - Geen database geselecteerd` komt van de databaseserver en wordt opgelost door stap 1 en 2; verwijder daarvoor geen constraints.

## Via de database-CLI

De aanbevolen projectwrapper gebruikt dezelfde databaseconfiguratie als de applicatie en geeft de SQL via PDO aan de databaseserver door. Voer vanuit de projectmap uit:

```text
php scripts/apply-capacity-targets-migration.php --execute
```

Dit commando leest host, poort, databasenaam, gebruiker en wachtwoord uit de bestaande `.env`, toont het wachtwoord niet, controleert de geselecteerde database en databaseversie, maakt de tabel alleen aan als die ontbreekt en valideert daarna het schema. De exact herkende eerdere variant met `UNSIGNED` op beide targetwaarden wordt veilig voorwaarts genormaliseerd naar signed doelkolommen; zo kunnen negatieve waarden in een niet-strikte SQL-modus niet eerst naar nul worden omgezet voordat de `CHECK` wordt uitgevoerd. Targetdata binnen de geldige grenzen blijft daarbij behouden. Bij iedere andere bestaande afwijking stopt het commando met `SCHEMA_MISMATCH` en verwijdert of repareert het niets automatisch. Inspecteer dan eerst `SHOW CREATE TABLE capacity_targets;` en maak een afzonderlijke voorwaartse herstelmigratie.

Zonder `--execute` is hetzelfde commando een veilige preflight. Als de tabel al correct bestaat, is ook `--execute` idempotent: het schema wordt gecontroleerd en er wordt niets gewijzigd. Voer geen rollback uit voor deze controle.

Wie rechtstreeks de lokale MySQL-client gebruikt, kan het wachtwoord interactief laten vragen. Voor de huidige lokale Wamp-configuratie is het commando vanuit de projectmap:

```text
& 'C:\wamp64\bin\mysql\mysql8.4.7\bin\mysql.exe' --host=127.0.0.1 --port=3306 --user=root --password geoform_db --execute="source database/sql/2026-08-07_create_capacity_targets.sql"
```

Controleer host, poort, gebruiker en databasenaam vooraf tegen de lokale `.env`; zet het wachtwoord niet in het commando. Voer daarna `php scripts/apply-capacity-targets-migration.php` uit voor de expliciete schemavalidatie.
