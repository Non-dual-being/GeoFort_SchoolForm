# Vier modules — uitvoering

Besluiten bevestigd op 12/13 oktober 2026. Branch: feature/education-four-modules.
Basis: main e93bc9230ffc7c9a9817319bafa78a46b8925489.

## Bevestigd

- Nieuwe bezoekdatums vanaf 4 januari 2027: standaard kort dagprogramma met vier modules.
- Drie vaste modules en één keuze voor de gehele aanvraag/school.
- PO: Dynamische Globe, Voedsel Innovatie, Speeltuin (bestaande key Klimparcours).
  Keuze: Minecraft Klimaat, begeleide KE, of voor uitsluitend groep 7/8 Stop de Klok/Earth Watch.
- Beide VO-sectoren: DG, VI, begeleide KE. Keuze EW, SDK, Minecraft Klimaat/Programmeren, Speeltuin.
- 10:00–14:00; vier rondes van 45 minuten, lunch 11:45–12:15.
- Groepsnorm 19; tot 160 leerlingen. Documentatie staat overschrijding van de norm bij acht groepen toe.
- Eén docent per groep; gedeelde ruimte- en modulecapaciteit blijven afzonderlijke harde regels.
- Huidige leerlingtarieven; bij vier modules geen entreekosten voor extra schoolbegeleiders.
  Aantallen en minimumbegeleidingsmelding blijven bestaan. Bestelde catering blijft betaald.
- Vijfmodulesformulier via aparte link met dashboardinstelling open/gesloten.
  Gesloten: vriendelijke boodschap en link naar vier modules. Bestaande aanvragen blijven beheerbaar.
- Geen aansluitende nieuwe middagstart. Geen automatische omzetting van bestaande boekingen.

## Eerste bouwstap

- FourModuleProgram: datumgrens, moduleaanbod/keuze, tijden, groepsvoorstel, docentenaantal.
- BookingPricingInput: expliciete optionele programmaversie; bestaande checksumgegevens blijven gelijk.
- BookingPriceCalculator: begeleiderskosten vervallen uitsluitend bij de nieuwe versie.
- Domeintest: grensdatums, groepsgrenzen, gemengde PO-leerjaren, schoolbrede keuzes en prijsverschil.

## Tweede bouwstap — 13 oktober

De formulierflow, prijsopgave en nieuwe aanvraag gebruiken nu dezelfde servergestuurde versie.
Vanaf de ingangsdatum kiest de standaardflow vier modules; `?variant=five` kiest het bestaande aanbod
en `?variant=four` het nieuwe. De server controleert datum en schoolbrede modulekeuze opnieuw.
De versie wordt opgeslagen in `aanvragen.program_version` en behouden bij wijziging, prijsvoorbeelden
en mailvoorbereiding. Bestaande rijen blijven NULL en krijgen nooit op basis van de datum een nieuwe versie.

Dashboard: een toegankelijke open/gesloten-schakelaar, aparte CSRF-scope en toegangscontrole voor admin/planner.
De instelling heeft een revisienummer om verouderde wijzigingen te weigeren. De inschrijftransactie
herleest en vergrendelt de instelling: ook een al geopend vijfmoduleformulier wordt bij sluiting geweigerd.
De migratie begint met open om het bestaande aanbod te behouden tot de bewuste overgang. Een oud formulier zonder variantveld behoudt het vijfmoduleaanbod; ook daarvoor geldt de sluitingscontrole.

Aanvraagmails gebruiken de nieuwe programmanaam en vaste modules. In deze bouwstap waren de
viermodulevoorbeelden nog niet beschikbaar; deze zijn in de publieke praktijkproef hieronder toegevoegd.

### Verificatie

- FourModuleProgramDomainTest: 69 controles.
- FourModuleBookingPersistenceTest: versieopslag in geïsoleerde SQLite, wijzigingen, prijsvoorbeelden,
  mailgegevens, mailinhoud en voorkomen van een verkeerde oude roosterbijlage.
- FourModuleAccessAndConfigTest: 24 controles op datumselectie, configuratie, rollen, revisieconflict en sluiting.
- Bestaande programma-, programmaconfiguratie-, prijs-, prijssnapshot-, aanvraagmail-, mailfout- en frontendcontracttests groen.
- Vue/TypeScript en Vite-build geslaagd.
- Dashboardschakelaar in een lokale componentproef met gesimuleerde API: open/sluiten en bediening met spatie gecontroleerd.

## Derde bouwstap — roosterketen en testronde

De opgeslagen programmaversie stuurt nu de roosterwizard, personeelsbezetting, validatie,
optimalisatie en definitieve documenten. Vier rondes met lunch na ronde twee; iedere groep
heeft een eigen bevoegde GeoFort-begeleider, ook bij KE en Speeltuin. Een kok blijft een aparte rol.
Bij omzetting naar een aangepast rooster blijft de versie behouden. Oude en ochtendroosters
houden hun eigen regels. De gezamenlijke optimalisatie controleert dezelfde harde regels.

De personeelszoeker heeft voor vier modules een zoekgrens. Een zware proef met acht breed
inzetbare docenten leverde in circa 6–9 seconden een geldig voorstel op. Als de grens wordt
bereikt, meldt het voorstel dat niet alle mogelijkheden zijn onderzocht; het is dan geen
garantie op het beste mogelijke rooster. Harde regels worden niet versoepeld.

### Uitgevoerde controles

- FourModuleRosterIntegrationTest: 121 controles in tijdelijke MariaDB-databases.
  PO/VO met 2, 4 en 8 groepen, inclusief 160 leerlingen; genereren, opslaan, ongeldige
  begeleiding weigeren, definitief maken, PDF/Excel, heropenen en aangepaste roosters.
- FourModuleJointIntegrationTest: 24 controles; vier+vier, vier+vijf en vier+ochtend,
  gezamenlijke optimalisatie en finalisatie, plus gezamenlijke Excel-export.
- Echte Vue-wizard in geïsoleerde browserproef met backend-testgegevens en gesimuleerde API:
  vier rondes, lunch, modulekeuze, personeelskeuze, voorstelverzoek en desktop/mobiel;
  geen runtimefouten. Dit vervangt geen volledige browserproef van het publieke formulier.
- Bestaande tests voor ochtendregels, gedeelde capaciteit, gezamenlijke roosters, finalisatie,
  schooldocumenten en mailbijlagen geslaagd. Mail werd afgevangen in de testomgeving.
- Bestaande Excel-writertest: 8 fixtures, 52.920 controles; exportcontract geslaagd.
- Bestaande boekings-, prijs-, programmaversie- en frontendcontracttests geslaagd.
- Vue/TypeScript/Vite-build en PHP-syntaxcontroles geslaagd. Bestaande buildwaarschuwing
  voor form-heightlines.png blijft bestaan.
- Definitieve PDF met acht groepen: alle drie pagina's gerenderd en visueel beoordeeld;
  vier lesrondes, lunch 11:45–12:15 en vertrek 13:45–14:00.

### Belangrijkste gewijzigde onderdelen

- RosterPlanningConfig, RosterGroupCountResolver, RosterGenerationTemplateProvider en
  RosterManualProgramPolicy: versieafhankelijke modules, groepsnorm, begeleiding en dagindeling.
- RosterPlanService, RosterPlanSqlRepository en RosterSourceFingerprint: versiebehoud en opslag.
- RosterAutoGenerator, RosterStaffOptimizer, RosterSessionService, RosterValidationService,
  RosterDayRules en RosterLifecycleService: genereren, veilig optimaliseren en definitief vastleggen.
- RosterAutoGeneratorPanel.vue, DashboardRosterCreateView.vue en roostertypes: wizardweergave.
- Nieuwe integratie- en browsertests; DisposableBookingMariaDb en RosterLifecycleFixtures
  voeren de nieuwe migraties uitsluitend in tijdelijke testdatabases uit.

### Migraties voor latere uitrol

1. 2026-10-13_add_booking_program_version.sql
2. 2026-10-13_create_booking_program_access.sql
3. 2026-10-13_add_roster_manual_program_version.sql

### Resterende publicatievoorbereiding

- Publieke formulierpraktijkproef en voorbeeldroosters zijn afgerond (zie hieronder).
- Laatste integratiereview van de volledige branch, releasepakket en herstelplan volgen nog.
- De bestaande minimumregel voor schoolbegeleiding blijft voorlopig behouden; de groepsnorm 19 vervangt die niet stilzwijgend.

Geen migraties op bestaande databases uitgevoerd, geen echte mail verstuurd, geen merge of deployment.

## Publieke voorbeeldroosters en formulierpraktijkproef — 13 oktober 2026

### Uitvoering

- 72 nieuwe voorbeelden, elk als PDF en PNG: vier PO-keuzes en vijf VO-keuzes,
  voor één tot acht groepen. VO onderbouw en bovenbouw delen dezelfde voorbeelden.
- Vier lesrondes, lunch 11:45–12:15 en einde 14:00. Iedere groep volgt alle vier
  modules; maximaal twee groepen volgen dezelfde module tegelijkertijd.
- BookingRosterResolver selecteert de voorbeelden uitsluitend voor de opgeslagen/gekozen
  viermodulevariant en valideert eerst de toegestane keuze voor de onderwijsselectie.
- Preview, download en conceptmail gebruiken hetzelfde bestand. Het blijft uitdrukkelijk
  een voorbeeld; definitieve schoolroosters worden door de bestaande finalisatieketen gemaakt.
- Kleine formuliercorrecties: enkelvoud/meervoud bij groepen, schoolbegeleiders gratis
  in de viermodulesamenvatting en een afbreekbare titel op smalle schermen.

### Geslaagde controles

- FourModulePublicExamplesTest: 4.045 controles op aanbod, groepsrotatie, gedeelde
  capaciteit, bestanden, resolutie en veilige mailbijlage-resolutie.
- Alle 72 PDF's gecontroleerd op één pagina, juiste tijden, groepskolommen, vier bezoeken
  per groep en tekst binnen de paginaranden. Alle negen modulefamilies visueel beoordeeld.
- Echte publieke PHP-endpoints en gebouwde Vue-app doorlopen in Chrome met een tijdelijke
  MariaDB-database: PO vier (40 leerlingen), VO vier (160 leerlingen, 180 remiselunches)
  en bestaande VO vijf (57 leerlingen).
- Prijsopgave is exact gelijk aan de opgeslagen prijssnapshot. Extra schoolbegeleiders
  kosten bij vier modules geen entree; catering blijft betaald. Vijf behoudt bestaande tarieven.
- Drie mails lokaal afgevangen: PDF-bijlagen bytegelijk aan de downloads, bedragen gelijk
  aan de opgeslagen prijsopgave. Geen mail naar echte ontvangers verstuurd.
- Zes ongeldige aanvragen geweigerd zonder opslag/mail: te vroege viermoduledatum,
  onbekende variant, 161 leerlingen, ontbrekende begeleiding, ontbrekende voorwaarden
  en een niet-toegestane keuze voor gemengde PO-groepen.
- Automatische datumgrens 30-12-2026 / 04-01-2027 gecontroleerd. Gesloten vijfmoduleformulier
  toont de verwijzing naar vier; ook een eerder geopend vijfmoduleformulier wordt bij
  indienen geweigerd. Vier blijft beschikbaar.
- Bestaande regressies: 363 bovenbouwselecties, acht oude roosterbestandsparen en
  MariaDB-aanvraagopslag inclusief rollback/mailfout. De verouderde VO-onderbouwwaarde
  in één test is afgestemd op het al bestaande aanbod op main.
- Vue/TypeScript-productiebuild geslaagd; bestaande waarschuwing over form-heightlines.png.

### Reproduceerbaarheid en bestanden

- Bron en generator: `src/Services/Booking/Roster/FourModuleExampleRoster.php` en
  `scripts/build-four-module-examples.php` (PHP + Dompdf; Poppler-pad als argument).
- Uitvoer: `public/assets/booking/roosters/{pdf,images}/four-2027-*`.
- Praktijkproef: `scripts/tests/fourModulePublicFormBrowserTest.mjs`, met
  `STATUS_TEST_DB_CONFIRM=YES_DISPOSABLE` en lokale MariaDB-instellingen.
- PDF-/mailcontrole: `scripts/tests/fourModulePublicPdfReview.py` (pdfplumber en Pillow).
- Lokale bewijzen: `local-backups/four-module-public-review/` (niet voor publicatie).
- Bij de release expliciet gewijzigde applicatiebestanden selecteren; lokaal geïnstalleerde
  vendor-afhankelijkheden, tijdelijke bestanden en testbewijs niet blind meestagen.

Werk blijft op `feature/education-four-modules`; main en live zijn niet aangepast.

## Branchreview en releasevoorbereiding — 13 oktober 2026

Releaseplan: [four-modules-release.md](four-modules-release.md).

- Hele wijzigingsset nagelopen: formulier, versieopslag, historisch behoud, dashboard,
  prijs/mails, roosterketen, definitieve documenten en migraties.
- Reviewfixes: DashboardBookingDetailService/SqlService leveren versiegebonden modulekeuzes;
  RosterPlanService behoudt de versie ook bij direct gekoppeld maatwerk; BookingGeoFormView
  toont bij ochtend de oorspronkelijke begeleidersinformatie.
- 29 geselecteerde release-regressies geslaagd, waaronder 127 viermoduleroostercontroles,
  24 gezamenlijke controles, 52 boekings-/dashboardcontroles, 14 HTTP-autorisatiecontroles
  en 10 migratie-/herstelcontroles. Bestaande documenten/mail, ochtend, prijssnapshots,
  363 bovenbouwselecties en Excel zijn meegenomen.
- Publieke Chrome-praktijkproef herhaald op de releasebuild: drie complete aanvragen,
  exacte prijzen/bijlagen, zes geweigerde aanvragen, datumgrens en sluiten van het oude formulier;
  aanvullend ochtend via de viermodulelink met behoud van betaalde extra begeleiders.
- 64 gewijzigde/nieuwe PHP-bestanden syntaxgeldig; Vue/TypeScript/Vite en diffcheck geslaagd.
  Composer-configuratie en lokale platformvereisten geldig. Bestaande waarschuwingen:
  ontbrekend licentieveld in composer.json en form-heightlines.png in Vite.
- Releasemiddelen: scripts/release/FourModuleSchema.php, four-module-schema.php en
  package-four-modules.py. Alleen de expliciete migratie-opdracht schrijft schema; inspectie
  en rollback-check zijn read-only. Archieven/snapshots tellen mee in de rollbackblokkade.
- De helper accepteert alleen passende bestaande kolommen, bewaart oude rijen en kan veilig
  herhaald worden. Geen downmigration of automatische databaseterugzetting.
- De huidige origin/main-revisie is gecontroleerd: e93bc9230ffc7c9a9817319bafa78a46b8925489.
  Productieomgeving en herstel van een actuele productiebackup moeten bij uitrol nog gecontroleerd worden.
- Lokale testlogs: local-backups/four-module-release-review/; geen productiewijzigingen of echte mail.

## Integratieoverzicht

1. Programmaversie duurzaam opslaan en doorgeven via BookingRequestData, StoredBooking/Assembler,
   SQL-repositories en prijsinput-factories. Historische lege versies behouden oud gedrag;
   nooit classificeren op bezoekdatum. Wijziging van versie is een expliciete dashboardactie.
2. Dashboardinstelling voor nieuwe vijfmoduleaanvragen: persistente setting, private sessie,
   actiegebonden CSRF, controle bij openen én indienen. Ochtend blijft afzonderlijk ondersteund.
3. BookingProgramConfig/API uitbreiden met versiecontext; formulierkeuze, validatie,
   samenvatting, begeleidersminimum en tariefweergave daarop aansluiten. Geen globale wijziging
   van de huidige dagconfiguratie of bestaande catalogus.
4. RosterPlanningConfig, wizard, validator en gezamenlijke optimalisator versieafhankelijk maken:
   vier rondes, norm 19, één docent per groep en begeleide KE. Bestaande capaciteitsgrenzen behouden.
5. Mails, school-PDF, voorbeeldroosters en Excel vanuit de opgeslagen versie genereren.
6. Acceptatieset: nieuwe PO/VO, oud/vijf ongewijzigd, datumgrens, gesloten formulier ook via API,
   contactwijziging oude aanvraag, bewuste programmaomzetting, vier+vier, vier+vijf, vier+ochtend,
   groepsgrenzen en 160 leerlingen, definitieve documenten en afgevangen mail.

## Bestaande aansluitpunten

- src/Booking/BookingProgramConfig.php: huidig publiek aanbod, sectoren, modulekeys en informatieteksten.
- src/Services/Booking/Pricing/: catalogus, berekening, snapshots en checksums.
- src/Booking/Stored/ en src/Services/Booking/Data/: opgeslagen aanvraag en nieuwe formulierinput.
- src/Services/Booking/ProgramConfiguration/: wijzigen programma in dashboard.
- src/Services/Dashboard/Roster/RosterPlanningConfig.php: sessiebegeleiding en gedeelde capaciteit.
- resources/js/composables/useEducationProgram.ts en resources/js/types/booking/BookingProgramConfigTypes.ts.
- resources/js/admin/components/bookings/BookingProgramPanel.vue: bestaande dashboardflow.

## Nog expliciet te bepalen voor publicatie

- Beginstand van de vijfmoduleschakelaar en definitieve publieke URL's.
- Minimum schoolbegeleiding voor de nieuwe variant (niet afleiden uit de oude gratis-begeleidersratio).
- Behandeling van bestaande toekomstige vijfmoduleafspraken: behoud is standaard, omzetten is bewust.
- Afzonderlijke ochtendwensen (09:30/10:00 en publieke weekdagen) behoren niet automatisch tot deze stap.
