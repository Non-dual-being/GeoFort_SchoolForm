# Vier modules — releasekandidaat en herstelplan

Status: lokaal voorbereid, niet gemerged en niet live. Doelbranch: main, bij voorkeur fast-forward
van e93bc9230ffc7c9a9817319bafa78a46b8925489 naar de exact geteste kandidaat.
Het concrete commitnummer staat in candidate.json naast het pakket. Een gewijzigde main,
afwijkende serverrevisie of codewijziging vereist opnieuw vergelijking en relevante tests.

## Inhoud en grenzen

- source.tar.gz: gecommitteerde broncode, tests, drie migraties en 72 PDF/PNG-voorbeeldparen.
- build.tar.gz: de geteste productiebuild voor formulier én dashboard.
- FILES.sha256: SHA256 van ieder bron-/buildbestand; SHA256SUMS controleert de pakketbestanden.
- candidate.json en changed-files.txt: exacte bronrevisie, basis en wijzigingslijst.
- Geen .env, wachtwoorden, productiegegevens, testmails, testdatabases of lokale vendor-bestanden.
- Dependencies installeren vanuit de ongewijzigde composer.lock, als deploy-gebruiker, nooit interactief/root.
- Publieke URL's: / (datumgestuurd), /?variant=four, /?variant=five. Nieuwe bezoekdatums vanaf
  04-01-2027 krijgen standaard vier modules. De bestaande vijfmodulevariant begint open;
  de planner sluit deze bewust met de dashboardschakelaar. Ochtend blijft apart beschikbaar.
- Bestaande aanvragen en prijzen blijven hun oorspronkelijke versie behouden. Geen omzetting/backfill.
- Schoolbegeleiders bij vier modules betalen geen entree; catering wel. Huidig minimum voor
  schoolbegeleiding en publieke ondergrens 40 leerlingen blijven behouden.

## Lokale review

De wijziging is nagelopen op versieopslag en historisch behoud, prijsopgave/snapshots/mail,
publieke validatie, dashboardaanpassingen, wizard, personeelsregels, gezamenlijke optimalisatie,
finalisatie, export en migraties. Gevonden en hersteld:

1. Het dashboardaanvraagdetail bood nog de vijfmoduleconfiguratie aan; het gebruikt nu de
   opgeslagen versie, de bijbehorende keuzes/filters en een herkenbare programmanaam.
2. Rechtstreeks een maatwerkrooster aan een viermoduleaanvraag koppelen verloor de versie;
   de koppeling behoudt nu versie en eindtijd. Omzetting vanuit een bestaand rooster deed dit al.
3. De publieke ochtendkeuze kon tekst over gratis viermodulebegeleiders erven; ochtend
   gebruikt nu weer de bestaande programmagegevens.

Aanvulling na plannercontrole: KE en Vleermuizen Speurtuin mogen bij vier modules
expliciet School krijgen. Controle toont waarschuwingen; finalisatie accepteert de
afwijking en bewaart deze in de snapshot. De wizard blijft GeoFort-begeleiding
plannen. Ochtendstations blijven verplicht begeleid. Dit is aanvullend getest
voor individuele en gezamenlijke finalisatie.

Regressies omvatten de publieke aanvraagketen, oude bovenbouw/ochtend, prijssnapshots,
4+4/4+5/4+ochtend, definitieve schooldocumenten, lokaal afgevangen mail en Excel.
Extra tests controleren echte sessie-/CSRF-afwijzingen, revisieconflicten, herhaalbare migratie,
behoud van oude data en de blokkade op onveilige code-rollback. Zie het implementatieverslag
en het afzonderlijke lokale reviewrapport voor de exacte resultaten.

## Uitrolvolgorde — pas uitvoeren na releasebesluit

De hieronder genoemde serverpaden volgen de vorige deployment. Verifieer ze op de server;
dit pakket heeft tijdens voorbereiding geen serververbinding gemaakt.

1. Controleer candidate.json, SHA256SUMS en de diff. Merge uitsluitend deze kandidaat naar main.
   Noteer de huidige live REVISION en het doel van /var/www/onderwijsboeking-v2/current.
   Die revisie moet overeenkomen met de pakketbasis. Bij afwijking stoppen.
2. Neem de bestaande deploy-lock /home/deploy/.deploy-onderwijsboeking-v2.lock. Zet **alle
   schrijvende toegang** voor deze applicatie in onderhoud: publiek formulier, dashboard,
   API en eventuele achtergrondtaken. Het vijfmoduleschuifje is geen onderhoudsschakelaar.
   Gebruik de bestaande siteconfiguratie; controleer van buitenaf dat writes niet meer mogelijk zijn.
   Stop of reboot niet de hele server. Houd de bestaande SSH-sessie open.
3. Maak onder /home/deploy/backups een unieke, alleen voor beheerders leesbare runmap.
   Bewaar vorige releasepad/revisie, .env-checksum, siteconfiguratie en gedeelde documentbestanden.
   Maak een volledige consistente databasedump van onderwijsboeking_v2, inclusief triggers,
   routines en events. Bewaar checksum en controleer gzip. Controleer vóór activatie ook een
   herstel naar een afzonderlijke database; nooit hiervoor productie overschrijven.
4. Upload het pakket naar een afzonderlijke map en voer daar `sha256sum -c SHA256SUMS` uit.
   Maak een nieuwe release onder /var/www/onderwijsboeking-v2/releases. Neem bestaande
   runtimekoppelingen en de oude gehashte frontendassets over uit de vorige release.
   Pak source.tar.gz en build.tar.gz over de nieuwe release uit. Zet/verifieer .env als
   koppeling naar /var/www/onderwijsboeking-v2/shared/.env. Behoud documentopslagkoppelingen.
   Pas eventuele verwijderingen uit changed-files.txt alleen binnen de nieuwe release toe.
5. Installeer dependencies als deploy in deze nieuwe release:

   ```sh
   composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
   composer check-platform-reqs --no-dev
   ```

   Gebruik dezelfde PHP 8.3 als FPM. Installeer uitsluitend de lockfileversies; geen composer update.
   Verifieer vervolgens FILES.sha256 vanuit de nieuwe release. Laat de oude gehashte
   frontendbestanden staan voor reeds geopende browsers. Rechten: deploy:www-data,
   bronbestanden leesbaar voor FPM, geheime/gedeelde gegevens behouden hun eigen rechten.
6. Start de schema-inspectie vanuit de nieuwe release (CLI, geen HTTP):

   ```sh
   php8.3 scripts/release/four-module-schema.php inspect
   ```

   De basis vereist bestaande aanvragen, roster_manual_plan_context en roster_plan_snapshots.
   Bij ontbrekende basistabellen of afwijkende kolomdefinities stoppen. Voor de eerste migratie
   zijn de drie nieuwe schemaonderdelen afwezig. Een gedeeltelijke eerdere poging mag alleen
   worden hervat als bestaande onderdelen kloppen; een beschadigde instelling vereist inspectie.
7. Met writes nog steeds geblokkeerd: voer de drie migraties gecontroleerd uit met de
   geverifieerde gzip-back-up en zijn eerder vastgelegde SHA256 als argumenten:

   ```sh
   FOUR_MODULE_MAINTENANCE_CONFIRMED=YES php8.3 scripts/release/four-module-schema.php migrate /absoluut/pad/production-before.sql.gz BACKUP_SHA256
   ```

   DDL is niet transactioneel. De helper gebruikt een database-lock en slaat reeds bestaande,
   gevalideerde onderdelen over. Hij verandert geen bestaande aanvraag of roosterinhoud en
   zet een eerder gesloten instelling bij herhaling niet terug open. Verwacht schemaReady=true.
8. Controleer bootstrap als www-data en PHP-syntax, zet REVISION op de kandidaat en noteer
   het nieuwe releasepad. Wissel current atomair met een tijdelijke symlink op hetzelfde
   bestandssysteem. Herlaad php8.3-fpm. Houd onderhoud actief tijdens de eerste controles.
9. Controleer de routes en de onderstaande acceptatielijst. Bij falen: herstelplan volgen.
   Bij succes: onderhoud opheffen en direct nogmaals controleren. Bewaar de vorige release,
   back-ups en logs. Wis of roteer de backups niet tijdens deze release.

## Live acceptatie

- `/`, `/?variant=four`, `/?variant=five`, login en programma-config-API leveren de verwachte inhoud.
- Op een datum vanaf 04-01-2027 toont vier drie vaste modules, één keuze, einde 14:00,
  norm 19 en gratis schoolbegeleiders. Download klopt bij schooltype/keuze/groepsaantal.
- Een oude toekomstige aanvraag blijft vijf modules; prijs en opgeslagen definitieve documenten
  blijven gelijk. Aanvraagdetail/editor, ochtendprogramma en de weekdaguitzondering blijven werken.
- Admin/planner kan vijf openen/sluiten; ongeldige CSRF en ongeautoriseerde rollen worden geweigerd.
  Een reeds geopend vijfmoduleformulier kan na sluiting geen nieuwe aanvraag opslaan.
- Kleine gemarkeerde testaanvraag, eigen ontvanger kevin@geofort.nl: aanvraag, prijs, concept-PDF,
  roosterwizard, optimalisatie, definitieve school-PDF en Excel controleren. Geen echte scholen mailen.
  Controleer vóór verzenden alle To/CC/BCC; eerdere toestemming voor twee gezamenlijke testmails
  is geen opdracht om nu extra testmails te sturen. Maak testdata/benodigde mails eerst reviewbaar.
- Foutlogs en PHP-FPM controleren op nieuwe fouten. Bezoekdatums/echte aanvragen niet wijzigen.

## Herstelplan

**A. Fout vóór activatie:** current blijft op de oude release. Onderhoud aanhouden tot de oorzaak
duidelijk is. Toegevoegde nullable kolommen en instelling mogen blijven staan; oude code negeert
ze. Herhaal niet blind losse SQL: de schemahelper controleert wat al is uitgevoerd.

**B. Fout na activatie, nog geen viermodulegegevens:** onderhoud opnieuw/blijvend inschakelen,
writes én jobs stoppen. Voer vanuit de nieuwe release uit:

```sh
php8.3 scripts/release/four-module-schema.php rollback-check
```

Alleen exitcode 0 en codeRollbackAllowed=true staan terugschakelen van code toe. Dit controleert
versionedBookings, versionedCustomPlans, fourModuleSnapshots en fourModuleArchives. Wissel current atomair terug
naar het vastgelegde vorige releasepad, herlaad FPM, controleer REVISION/.env-checksum, oud
formulier, login en bestaande definitieve PDF. Houd de extra schemaonderdelen intact. Er is
geen databaseterugzetting nodig en er gaan geen tussentijdse vijfmoduleaanvragen verloren.

**C. Er zijn al viermodulegegevens (exitcode 3):** niet terug naar oude code die deze aanvragen
als vijf modules behandelt. Onderhoud aanhouden; nieuwe dump en documentbackup maken.
Voorkeur: gerichte forward-fix op de versiegeschikte code. Een volledige databaserestore is een
apart besluit: eerst alle sinds de backup ontvangen aanvragen, roosterwijzigingen en mailacties
inventariseren en veiligstellen. Geen automatische downmigration, geen tabellen/kolommen
verwijderen en geen blind restore waardoor nieuwe aanvragen verdwijnen. Test herstel eerst in
een aparte database en controleer verwijzingen naar definitieve documenten/snapshots.

**D. Backup onleesbaar, schema onverwacht of migratie onderbroken:** stoppen; huidige release
en onderhoudstoestand behouden, logs bewaren. Geen geforceerde rerun of OS-upgrade.

## Nog op het live moment vast te leggen

- Werkelijk live revisienummer, exacte runtime-/documentkoppelingen, onderhoudsmethode en
  actieve achtergrondtaken. Dit zijn omgevingscontroles, geen lokaal bewezen feiten.
- Succesvolle herstelproef van de actuele productiebackup in een aparte database.
- Tijdstip van uitrol en gewenste moment om het vijfmoduleformulier te sluiten.
- Main/live zijn pas klaar na de controles hierboven; het lokale pakket activeert niets zelf.
