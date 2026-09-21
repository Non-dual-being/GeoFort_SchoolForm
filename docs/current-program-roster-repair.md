# Herstel huidige onderwijsprogramma en roosters

## Run 1 — uitgevoerd op 19 september 2026

Doel: de huidige bovenbouw herstellen naar vier standaardactiviteiten plus één gekozen activiteit, de doorwerking controleren en run 2 uitsluitend lezend voorbereiden. Dit blijft een programma met vijf rondes. Geen roosterassets, echte boekingen, prijssnapshots, schema, prijzen, begeleidersregels, leerlinggrenzen, capaciteit, kalendergedrag, autorisatie, CSRF of mailbeveiliging gewijzigd. Geen staging, commit, push, merge, deployment, seeder, import of productietunnel.

### Basis en werkpaden

- **Herstelbranch:** `fix/current-program-roster-consistency`; bestond nog niet en is vanaf de gecontroleerde lokale HEAD aangemaakt.
- **Basiscommit en huidige HEAD:** `c7ac7d259501186602dfb539d30d90d004467d8d` (`Count existing bookings on blocked days in planning and analytics`).
- **Werkpad R:** `C:\wamp64\www\GeoFortForm4\local-backups\worktrees\current-program-roster-consistency`.
- **Oorspronkelijke checkout O:** `C:\wamp64\www\GeoFortForm4`, branch `feature/calendar-booking-clickability`, dezelfde HEAD.
- AGENTS.md gelezen; geen onderliggende AGENTS.md gevonden. Branches, worktrees, HEAD, volledige status, staged/unstaged diff en nieuwe bestanden gecontroleerd. De staged diff was en blijft leeg. De kalenderwijzigingen, twee al gewijzigde ochtendassets en afzonderlijke verwijdering in O zijn niet meegenomen naar R. Geen reset, stash of overschrijving.
- In `O/docs` is met `rg --files` en ook inclusief verborgen/genegeerde bestanden gezocht. `GeoFort_Herstel_Huidige_Versie_3_Codex_Runs.md` bevat de drie runopdrachten en is volledig als context gelezen. Geen afzonderlijke runbestanden of document met correcties/gespreksvoorbereiding voor Joëlle aangetroffen. Dit aangeleverde document blijft ongetrackt in O; SHA256 `AF862CE9EC545F255E5AB566D5E911A716B46516F0403F7BB8C74602C00CCDCE`.
- De gelezen HTTP- én HTTPS-vhost van de actieve Apache 2.4.65-service voor `onderwijsformulier.test` hebben documentroot `C:/wamp64/www/GeoFortForm4/public`, dus **O, niet R**. Dit is ook met een echte lokale GET op `/assets/booking/roosters/pdf/po_ochtend_standaard_2.pdf` bevestigd: de ontvangen SHA256 is die van de gecorrigeerde PDF in O (`C582780D...4B076E1`), niet die van R. WAMP/vhosts en `.env` zijn ongewijzigd. Er is voor R nog geen aparte browserserver gestart.

### Correctie en doorwerking

Alleen in `src/Booking/BookingProgramConfig.php` is de bestaande key `Stop-de-Klimaat-Klok` binnen `voortgezetBovenbouw/dag` verplaatst:

- Standaard: `Klimaat-Experience`, `Voedsel-Innovatie`, `Dynamische-Globe`, `Earth-Watch`.
- Precies één keuze: `Crisismanagement`, `Minecraft-Programmeren`, `Stop-de-Klimaat-Klok`.

De centrale correctie is voldoende voor de productiecode. De publieke `BookingProgramConfigAction` levert `forFrontend()`. `useEducationModules` en het beheerpaneel `BookingProgramPanel` gebruiken de gedeelde `getAvailableEducationModuleOptions`; het dashboard levert dezelfde centrale moduleconfiguratie. `BookingFormHandler`, `BookingRosterAction`, `StoredBookingProgramValidator`, `StoredBookingValidator` en `BookingProgramConfigurationService` gebruiken `ChoiceModuleSelectionValidator`. De roosterresolver leest de standaardmodules centraal en zoekt met sector, programma, exact gekozen modulekey en leerlingenaantal. De aanvraag- en bevestigingsmailer gebruiken dezelfde template met de actuele standaardlijst en de opgeslagen keuze. Geen extra frontendregels of boekingsuitzonderingen toegevoegd.

**Effect op bestaande boekingen en latere mails:** de volledige historische standaardlijst wordt niet opgeslagen. Een opnieuw opgebouwde mail bij een bestaande keuze Crisismanagement of Minecraft-Programmeren toont voortaan de vier juiste standaarden; Stop-de-Klimaat-Klok verdwijnt daar uit de standaardopsomming. Bij die nieuw toegestane keuze verschijnt de klok uitsluitend als keuze, eenmaal. Opgeslagen keuze, leerlingenaantal, begeleidersaantal en prijsafspraak blijven behouden. Eerder verzonden mails veranderen niet. Hiervoor zijn synthetische opgeslagen-boekingsfixtures en prijsdetails gebruikt; er zijn geen historische afspraken uit echte boekingen afgeleid en geen boekingsrijen gelezen of bijgewerkt.

### Controles en resultaten

PHP gebruikt: `C:/wamp64/bin/php/php8.3.28/php.exe` (PHP **8.3.28**), met CLI-opties `-d xdebug.mode=off -d xdebug.log=NUL` om de lokale Xdebug-log buiten het project niet te gebruiken.

- **Nieuwe PHP-regressie:** `scripts/tests/BookingUpperSchoolProgramRegressionTest.php`. Faalt vóór de correctie op de foutieve standaard-/keuzelijst; slaagt erna. Controleert publieke configuratie en validators, dashboardprogramma- en bevestigingsvalidatie, afwijzing van ontbrekende/meervoudige/ongeldige keuzes, alle 363 bovenbouwselecties (drie keuzes × 40–160), standaardlijst en exacte PDF/PNG-selectie. Bouwt zes mails op via een fake `MailInterface` (aanvraag en bevestiging per keuze), inclusief HTML, tekst, roosterbijlagen en lokale ontvangerbeveiliging. Vergelijkt keuze, aantallen, volledige opgeslagen prijsdetails, pricing-checksum en ongewijzigde fixtures vóór/na rendering. PO, VO-onderbouw, ochtend en de bestaande groepsgrenzen blijven geldig; 22 wordt geen nieuwe formuliergrens.
- **Disposable testdatabase:** alleen hardcoded `sqlite::memory:`, zonder `.env`, host of poort. Vóór tabelcreatie controleert de test driver `sqlite`, database `main` en een leeg bestandspad. Na fixtureopbouw staat `PRAGMA query_only=ON`; het aantal schrijfacties verandert tijdens validatie en mailopbouw niet. Geen muterende MySQL/MariaDB-tests uitgevoerd.
- **12 bestaande PHP-tests geslaagd:** `BookingProgramDomainTest.php`, `BookingProgramConfigurationDomainTest.php`, `BookingProgramUpdateHttpContractTest.php`, `BookingProgramConfigurationHttpContractTest.php`, `BookingProgramFrontendContractTest.php`, `BookingProgramConfigurationFrontendContractTest.php`, `BookingRequestMailTemplateTest.php`, `BookingMailTemplateRoutingTest.php`, `BookingRequestBccTest.php`, `BookingPriceFoundationDomainTest.php`, `BookingPriceSnapshotContractTest.php`, `LegacyBookingMapperTest.php` (alle onder `scripts/tests/`). De BCC-test produceert bewust meldingen over ontbrekende/falende fake bijlagen; hij slaagt zonder echte mail. De mappertest verwacht nu de geldige klokkeuze en behoudt zijn waarschuwingstest voor een historische standaardmodule met Earth-Watch. De mapper zelf is ongewijzigd; geen import uitgevoerd.
- **Frontendregressie geslaagd:** `node scripts/tests/bookingUpperSchoolModulesTest.mjs C:/wamp64/bin/php/php8.3.28/php.exe` vanuit R. Gebruikt de echte PHP-configuratie en bestaande TypeScript-helpers; alle **33** combinaties van bovenbouwniveau/groep en de drie keuzes slagen in de gedeelde helper en beheer-stapvalidatie. Ochtend houdt nul keuzes.
- **PHP 8.3-syntaxcontrole geslaagd:** gewijzigde configuratie, mappertest en nieuwe PHP-regressietest (`php.exe -l`).
- **TypeScript geslaagd:** vanuit O: `node node_modules/vue-tsc/bin/vue-tsc.js -b local-backups/worktrees/current-program-roster-consistency/tsconfig.json`.
- **Vite-build geslaagd:** vanuit R: `node C:/wamp64/www/GeoFortForm4/node_modules/vite/bin/vite.js build`. Bestaande waarschuwing: `./assets/images/form-heightlines.png` wordt tijdens de build niet gevonden en blijft voor runtime-resolutie staan. Build schrijft alleen genegeerde output in R. De eerdere `pnpm --dir ... build`-poging startte automatisch dependency-installatie en liep tegen geblokkeerde downloads aan; die is afgebroken en vervangen door bovenstaande aanwezige executables. Geen dependencies of lockfiles gewijzigd. De nieuw aangemaakte pnpm-cache is verplaatst naar de genegeerde inspectiemap.
- **`git diff --check` geslaagd**, inclusief afzonderlijke whitespacecontrole van de nieuwe bestanden zonder ze te stagen.

Niet uitgevoerd: volledige browser-/HTTP-interactie op R, echte SMTP, echte aanvraag of statusmutatie, muterende MariaDB-integratietests, productiecontrole en de gezamenlijke releasecontrole van run 3. De 92 PDF-inhouden zijn niet allemaal visueel beoordeeld; de bestandsinventaris is een bestaan-/padcontrole. Ochtendvarianten 3–5 zijn nog niet inhoudelijk vergeleken. De frontendcontrole betreft echte helpers en compilatie, geen bewijs dat de bestaande WAMP-site de nieuwe bovenbouwconfiguratie toont.

### Alleen-lezen roosterinventaris

Bron: de gecontroleerde lokale **MySQL 8.4.7** op `127.0.0.1:3306`, database `geoform_db`. De lokale listener en WAMP-databaseservices zijn gecontroleerd; `.env` wijst naar deze lokale ontwikkelverbinding. Het losse inspectiescript laadt geen applicatiebootstrap, controleert host/poort/databasenaam/omgeving vóór verbinden en verifieert `DATABASE()`, `@@port` en `@@session.transaction_read_only=1`. Alleen `SELECT` op roosterregistraties en servermetadata binnen `SET SESSION TRANSACTION READ ONLY`/een afgesloten transactie; geen productieverbinding, boekingsquery, schemawijziging of datamutatie.

- Actueel **92 registraties**. In O en R zijn elk **184** geregistreerde PDF-/PNG-paden op bestaan en exacte schrijfwijze van de aanwezige padonderdelen gecontroleerd. **183 aanwezig, één ontbrekend:** `/assets/booking/roosters/images/vo_onder_mp_10.png`, registratie 48. Geen hoofdletterafwijkingen bij de aanwezige paden gevonden.
- Voor elk van de drie bovenbouwkeuzes zijn **alle 121 aantallen 40–160** met de echte `RosterSqlService` en `BookingRosterResolver` gecontroleerd: precies één registratie per selectie, juiste groepsgrootteklasse, vier standaarden plus de keuze en beide juiste asset-URL's. Deze controle is na de configuratiecorrectie uitgevoerd; zij berust niet alleen op bestandsnamen.
- Exacte selectiewaarden voor de acht klokregistraties: frontendsector `voortgezetBovenbouw` → database-`schooltype` **`bovenbouw`**, `programmaduur` **`dag`**, `keuzemodule` **`Stop-de-Klimaat-Klok`**.

| Registratie | Leerlingen, inclusief | Groepen | Geregistreerde PDF | Geregistreerde PNG |
|---|---|---|---|---|
| 77 | 40–50 | 3 | `/assets/booking/roosters/pdf/vo_boven_sdk_3.pdf` | `/assets/booking/roosters/images/vo_boven_sdk_3.png` |
| 78 | 51–65 | 4 | `/assets/booking/roosters/pdf/vo_boven_sdk_4.pdf` | `/assets/booking/roosters/images/vo_boven_sdk_4.png` |
| 79 | 66–80 | 5 | `/assets/booking/roosters/pdf/vo_boven_sdk_5.pdf` | `/assets/booking/roosters/images/vo_boven_sdk_5.png` |
| 80 | 81–100 | 6 | `/assets/booking/roosters/pdf/vo_boven_sdk_6.pdf` | `/assets/booking/roosters/images/vo_boven_sdk_6.png` |
| 81 | 101–120 | 7 | `/assets/booking/roosters/pdf/vo_boven_sdk_7.pdf` | `/assets/booking/roosters/images/vo_boven_sdk_7.png` |
| 82 | 121–130 | 8 | `/assets/booking/roosters/pdf/vo_boven_sdk_8.pdf` | `/assets/booking/roosters/images/vo_boven_sdk_8.png` |
| 83 | 131–150 | 9 | `/assets/booking/roosters/pdf/vo_boven_sdk_9.pdf` | `/assets/booking/roosters/images/vo_boven_sdk_9.png` |
| 84 | 151–160 | 10 | `/assets/booking/roosters/pdf/vo_boven_sdk_10.pdf` | `/assets/booking/roosters/images/vo_boven_sdk_10.png` |

Alle zestien bovenstaande bestanden zijn aanwezig in beide checkouts. Crisismanagement selecteert `vo_boven_cmg_3` t/m `_10`; Minecraft-Programmeren selecteert `vo_boven_mp_3` t/m `_10`, in dezelfde PDF-/PNG-mappen en met dezelfde intervallen.

Onderbouw: `voortgezetOnderbouw` → `onderbouw`, `dag`, `Minecraft-Programmeren`, 151 én 160 selecteren groepenaantal 10, `/assets/booking/roosters/pdf/vo_onder_mp_10.pdf` en de ontbrekende PNG. De PDF bestaat, bevat één pagina en is technisch gerenderd en visueel gecontroleerd: VO, Minecraft-Programmeren, tien groepen, vijf lesrondes en de volledige tabel. SHA256: `F49BB98069B2F46F2550C3C7C9FBD847D0BCDC357FE1035BE9C71057FCEA31C8`. De sector onderbouw is met de registratie vastgesteld; de PDF-titel zelf noemt VO.

`po_skd_6.png` en `po_sdk_6.png` blijven afzonderlijke bestanden. Alleen **`po_sdk_6.png`** wordt geregistreerd (registratie 20, primair/dag/Stop-de-Klimaat-Klok, 81–100). Dat bestand bestaat in O en R. De losse verwijdering van `po_skd_6.png` in O is behouden. Het exemplaar in R hoort bij de basiscommit en is geen herstel van die verwijdering.

### Bevestigde ochtendindeling en versies

De nieuwste opdracht bevestigt onderstaande indeling op basis van Kevins screenshot. De oudere passage in het aangeleverde document waarin 10:35 nog vermoedelijk heet, is achterhaald. Ook de activiteitenverdeling is nu expliciet bevestigd; alleen de twee tijden in de oude tabel aanpassen zou niet de volledige bevestigde indeling opleveren.

| Tijd | Groep 1 | Groep 2 |
|---|---|---|
| 10:00–10:15 | Aankomst en welkom | Aankomst en welkom |
| 10:15–10:35 | Zandtafel | Dynamische-Globe |
| 10:35–10:55 | Deso. Tunnel + Rising-Risk | Dynamische-Globe-Bios |
| 10:55–11:10 | Pauze | Pauze |
| 11:10–11:30 | Expedition-Earth | Zandtafel |
| 11:30–11:50 | Dynamische-Globe | Deso. Tunnel + Rising-Risk |
| 11:50–12:10 | Dynamische-Globe-Bios | Expedition-Earth |
| 12:10–12:15 | Vertrek | Vertrek |

De gecorrigeerde bestanden staan al in **O** op de definitieve projectpaden. De PNG is bekeken; de PDF is met de bestaande Windows PDF-renderer naar een tijdelijke controleafbeelding gerenderd en bekeken. Beide tonen alle bovenstaande rijen en dezelfde activiteiten per groep. De oude PDF en PNG in R/basis tonen onder meer `10:15–11:35`, `11:35–10:55`, een andere verdeling voor groep 2 en geen vertrekrij. Geen van deze assets is in run 1 gewijzigd of overgenomen.

| Variant | Exact pad, relatief aan O of R hierboven | SHA256 |
|---|---|---|
| O, gecorrigeerde PDF | `public/assets/booking/roosters/pdf/po_ochtend_standaard_2.pdf` | `C582780D2ED34DB1A99F3DE0390CFDB9667E941B3EF529174045847DF4B076E1` |
| O, gecorrigeerde PNG | `public/assets/booking/roosters/images/po_ochtend_standaard_2.png` | `D95BB09841BDC99851B93536B1BAE7C609F44177DC5AA4A000BBDA3450778298` |
| R/basis, oude PDF | `public/assets/booking/roosters/pdf/po_ochtend_standaard_2.pdf` | `7C2BFABD026C9D1AF735EB67045CA8EE59CFEE637BE8E05FA8E48C9E4A86AD0D` |
| R/basis, oude PNG | `public/assets/booking/roosters/images/po_ochtend_standaard_2.png` | `21BC6B721DE90C212E0181B1CFECD56343AD1E0C0B77349A504CB20922617D10` |

In docs en de projectpaden is geen bewerkbare Excelbron of derde versie van dit tweegroepenexportpaar gevonden. Dat blokkeert deze correctie niet: het gecorrigeerde exportpaar is lokaal aanwezig en inhoudelijk geverifieerd. De database selecteert bij PO/ochtend/40 leerlingen met `Standaard-Ochtend-Programma-PO` precies dit paar en twee groepen. De formuliergrens blijft 40; de schoolspecifieke 22 leerlingen wijzigen niets. Ochtend houdt verder 41–50 → 3 groepen, 51–65 → 4, 66–80 → 5. De verhouding leerlingen/16 betreft begeleiders, niet de roostergroepsindeling.

### Overdracht naar run 2 en run 3

**Run 2:** blijf in R en controleer eerst de status en bovenstaande hashes. Neem uitsluitend de twee inhoudelijk goedgekeurde ochtendexports uit O over naar dezelfde relatieve paden in R; behoud de originelen. Render de ontbrekende `public/assets/booking/roosters/images/vo_onder_mp_10.png` technisch vanuit de hierboven gecontroleerde PDF, volgens de bestaande assetconventies. Controleer preview/PDF visueel, exacte padselectie en zo nodig de overige ochtendvarianten op aantoonbare fouten. Gebruik de bevestigde tijden en groepsindeling, vraag die niet opnieuw op. **Kevin hoeft voor deze twee reparaties geen extra bestand aan te leveren.** Een bewerkbare bron is alleen nog aanvullende invoer wanneer een verdere bronbewerking/export noodzakelijk blijkt. Richt voor browsercontrole een tijdelijke lokale server op R; de bestaande WAMP-URL toont O. Wijzig hiervoor geen WAMP/vhostconfiguratie. Laat de afzonderlijke verwijdering en kalenderwijzigingen in O intact.

**Run 3:** beoordeel de gezamenlijke diff vanaf de genoemde basiscommit, hergebruik geslaagde resultaten voor ongewijzigde code, voer de gezamenlijke release-/buildcontrole en browsergevallen op de daadwerkelijk aangepaste checkout uit (bovenbouw drie keuzes; PO-ochtend woensdag/40; onderbouw programmeren/151–160). Leg de visuele resultaten en beperkingen vast. Productiekoppelingen en bestanden zijn nog niet gecontroleerd; dat blijft een afzonderlijke controle vóór publicatie. Kevin verzorgt pas daarna de expliciete staging/commit/publicatie.

### Bestanden en eindstatus run 1

Wijzigingen in R: `src/Booking/BookingProgramConfig.php`, `scripts/tests/LegacyBookingMapperTest.php`.
Nieuw in R: `scripts/tests/BookingUpperSchoolProgramRegressionTest.php`, `scripts/tests/bookingUpperSchoolModulesTest.mjs`, `docs/current-program-roster-repair.md`.

Tijdelijke inspectiescripts, geschoonde roosterbevindingen, drie controleafbeeldingen en de via WAMP opgehaalde PDF staan genegeerd onder `O/local-backups/run1-inspection/`; buildoutput en compiler-cache staan genegeerd in R. Zij zijn geen releasebestanden. De originele aangeleverde runopdrachten blijven in O.

```text
## fix/current-program-roster-consistency
 M scripts/tests/LegacyBookingMapperTest.php
 M src/Booking/BookingProgramConfig.php
?? docs/current-program-roster-repair.md
?? scripts/tests/BookingUpperSchoolProgramRegressionTest.php
?? scripts/tests/bookingUpperSchoolModulesTest.mjs
```

O blijft op zijn oorspronkelijke branch, met uitsluitend de al aanwezige wijzigingen:

```text
## feature/calendar-booking-clickability...origin/main
 M public/assets/booking/roosters/images/po_ochtend_standaard_2.png
 D public/assets/booking/roosters/images/po_skd_6.png
 M public/assets/booking/roosters/pdf/po_ochtend_standaard_2.pdf
 M resources/css/admin/calendar.css
 M resources/js/admin/components/calendar/DashboardCalendarOverview.vue
 M resources/js/admin/components/calendar/DashboardCalendarOverviewDayCell.vue
 M scripts/tests/DashboardCalendarOverviewFrontendContractTest.php
 M scripts/tests/blockedDayPlanningBrowserTest.mjs
?? docs/GeoFort_Herstel_Huidige_Versie_3_Codex_Runs.md
```

## Run 2 — uitgevoerd op 20 september 2026

Uitgevoerd volgens `O/docs/GeoFort_Run_2_Roosterbestanden_Herstellen.md`. Alleen het goedgekeurde ochtendpaar is overgenomen, de ontbrekende onderbouwpreview is technisch gemaakt en dit verslag is aangevuld. De code en tests van run 1 zijn byte voor byte behouden. De oorspronkelijke checkout O is uitsluitend als leesbron gebruikt; alle nieuwe hulpmiddelen en bevindingen staan genegeerd onder `R/local-backups/run2-inspection/`.

### Beginsituatie en behoud

- AGENTS.md in O en R en het run-1-verslag gelezen; geen verdere AGENTS.md aangetroffen. R blijft op `fix/current-program-roster-consistency`, O op `feature/calendar-booking-clickability`. Beide HEADs blijven `c7ac7d259501186602dfb539d30d90d004467d8d`; beide staged diffs blijven leeg.
- De volledige beginstatus, binaire unstaged/staged diffs en SHA256 van alle door Git gevolgde en niet-genegeerde nieuwe bestanden zijn vóór de assetwijzigingen vastgelegd: **1166 paden in O, 1167 in R**. Ook het oorspronkelijke verslag en de hashes van de aanwezige `.env`-bestanden zijn vastgelegd, zonder hun inhoud uit te voeren of te publiceren. Bewijs: `before.json`, `original-before.patch`, `repair-before.patch`, beide `*-before-staged.patch` en `report-before.md` in de inspectiemap.
- R kwam exact overeen met de eindstatus van run 1. In O was alleen het inmiddels aangeleverde `docs/GeoFort_Run_2_Roosterbestanden_Herstellen.md` als extra ongetrackt document zichtbaar ten opzichte van het run-1-verslag; dat is gelezen en behouden.
- De eindvergelijking controleert alle oorspronkelijke bestanden, ontbrekende bestanden, branch, HEAD, staged/unstaged diff en volledige status van O. In R zijn uitsluitend de hieronder genoemde drie assets en dit verslag veranderd. De kalenderwijzigingen en de verwijdering van `po_skd_6.png` in O blijven intact. De overige ochtendvarianten, alle bron-PDF's behalve de bewust overgenomen ochtend-PDF, dependencies en lockfiles blijven ongewijzigd.

De vier code-/testbestanden van run 1 hebben vóór en na run 2 dezelfde SHA256:

| Bestand in R | SHA256 vóór = na |
|---|---|
| `src/Booking/BookingProgramConfig.php` | `F02F47292EEB80F2C28E8FCBC4E5DE0D8CAAE0D793FFD2E174E9139260B32E14` |
| `scripts/tests/LegacyBookingMapperTest.php` | `E0AE26246CA9E11B1AA222FFAFEFB0DFFAF301B74DCD441A4EAD779721F83BF0` |
| `scripts/tests/BookingUpperSchoolProgramRegressionTest.php` | `0E180371E17B71B696A338E5A0D435CA5E4AF443EB5CD390117E7CE4AD9D2D7B` |
| `scripts/tests/bookingUpperSchoolModulesTest.mjs` | `B03B597275EAD2BDE905C6AAABF1FB4DC0C1C56E1F7D3D799652DFB2FE607F67` |

### Overgenomen ochtendpaar en nieuwe onderbouwpreview

De ochtendbronnen staan op de hieronder genoemde relatieve paden in O en zijn met een gewone bestandskopie naar dezelfde paden in R overgenomen. Bronhashes kwamen vóór het kopiëren exact overeen met de goedgekeurde hashes uit run 1. Daarna zijn zowel SHA256 als de volledige byte-inhoud van bron en bestemming vergeleken: **beide paren identiek**. De twee ochtendbestanden zijn niet opnieuw gegenereerd of gerenderd.

| Bestand, relatief aan O/R | Herkomst en resultaat | Bytes in R | SHA256 in R |
|---|---|---:|---|
| `public/assets/booking/roosters/pdf/po_ochtend_standaard_2.pdf` | O → R, bron = bestemming | 83817 | `C582780D2ED34DB1A99F3DE0390CFDB9667E941B3EF529174045847DF4B076E1` |
| `public/assets/booking/roosters/images/po_ochtend_standaard_2.png` | O → R, bron = bestemming; 1684 × 1191 px | 105387 | `D95BB09841BDC99851B93536B1BAE7C609F44177DC5AA4A000BBDA3450778298` |
| `public/assets/booking/roosters/pdf/vo_onder_mp_10.pdf` | Bestaande bron in R, ongewijzigd | 109235 | `F49BB98069B2F46F2550C3C7C9FBD847D0BCDC357FE1035BE9C71057FCEA31C8` |
| `public/assets/booking/roosters/images/vo_onder_mp_10.png` | Nieuw, uitsluitend uit bovenstaande PDF; 1536 × 570 px | 49628 | `38B84AD0E41ED814D8BFC91829DF8FB306EDD1A9F591A402C758F20846B3265E` |

De onderbouw-PNG ontbrak bij aanvang. Vóór het renderen is de PDF technisch geopend: **één pagina**, normale rotatie, liggend A4, **841,92 × 595,32 PDF-punten** (1122,56 × 793,76 Windows-DIP). **Bronpagina 1** bevat het volledige voorbeeldrooster.

Renderer: de aanwezige **Windows.Data.Pdf.PdfDocument / PdfPage.RenderToStreamAsync**, `Windows.Data.Pdf.dll` versie **10.0.26100.8875**. `DestinationWidth = 1024` leverde op deze Windows-installatie een volledige paginarender van **1792 × 1267 px** op. Alleen de onderbouw-PDF is in deze run gerenderd. Er zijn geen applicatiedependencies geïnstalleerd of gewijzigd en er is geen generatieve afbeelding gebruikt.

De aangrenzende previews zijn bekeken en op afmetingen gecontroleerd: `vo_onder_mp_9.png` is 1207 × 572 px; `vo_onder_sdk_10.png` is 1600 × 617 px en `vo_onder_mw_10.png` is 1601 × 620 px. Die tonen de tabel met weinig buitenmarge. Daarom is bij de nieuwe preview uitsluitend leeg papier afgesneden met System.Drawing: rechthoek **x=102, y=109, breedte=1536, hoogte=570**, met **6 px marge rondom de volledige tabel**. Er is niet geschaald; de oorspronkelijke verhoudingen en alle behouden pixels blijven exact gelijk aan de PDF-render. De PNG heeft overeenkomstig de aangrenzende bestanden 120 dpi als metadata (uitgelezen als circa 119,9896).

Alle verwijderde pixels zijn gecontroleerd als wit of vrijwel wit, met iedere RGB-component minimaal 254; dit omvat de nauwelijks zichtbare rasterrand van de PDF-pagina. Geen tekst, tabelrand of kleurvlak is verwijderd. Na opslaan en opnieuw openen is **iedere pixel van de PNG exact gelijk aan het overeenkomstige deel van de volledige paginarender**. De bron-PDF heeft na afloop nog steeds dezelfde SHA256. De volledige render, render-/uitsnedescripts en `lower-render.json` staan uitsluitend in de genegeerde inspectiemap.

### Gerichte controles en beperkingen

- **Ochtendpaar:** de overgenomen PDF is opnieuw technisch geopend en de overgenomen PNG is op oorspronkelijke resolutie bekeken. Voor de visuele PDF-vergelijking is de bestaande, in run 1 gecontroleerde afbeelding `O/local-backups/run1-inspection/morning-current.png` opnieuw bekeken; de bijbehorende PDF-bronhash is identiek aan de nu gekopieerde PDF. Beide tonen alle acht bevestigde tijdvakken van 10:00–10:15 tot 12:10–12:15, dezelfde activiteiten per groep, de pauze en de vertrekrij. Tekst en tabel zijn volledig en leesbaar. Geen schoolspecifieke aantallen of contactgegevens toegevoegd.
- **Onderbouwpaar:** de volledige paginarender, de bestaande controleafbeelding uit run 1 en de uiteindelijke PNG zijn visueel vergeleken. Titel Minecraft-Programmeren, groep 1 t/m 10, aankomst, pauze, lunch en alle vijf lesrondes tot 14:45 zijn aanwezig. Ook de lange activiteitnamen en de rechter- en onderrand zijn volledig zichtbaar; kleuren en indeling komen overeen. De PDF-titel noemt VO; de onderbouwselectie en het bereik 151–160 komen uit de eerder vastgelegde registratie, niet uit een toegevoegd opschrift.
- **Paden en koppelingen:** met het bestaande `O/local-backups/run1-inspection/roster-inspection.json` gecontroleerd dat PO/ochtend/40 exact het tweegroepenpaar selecteert en onderbouw/dag/Minecraft-Programmeren bij 151 én 160 exact `vo_onder_mp_10.pdf` en de nieuwe PNG selecteert. Alle vier paden inclusief ieder maponderdeel en de bestandsnaam zijn op exacte hoofdletters gecontroleerd via de feitelijke directorynamen. Geen databaseverbinding of nieuwe resolver-/codetest uitgevoerd. Resultaten staan in `asset-checks.json`.
- **Behoud en whitespace:** de eindmanifestcontrole slaagt: alle **1166** oorspronkelijke paden en hun inhoud/status zijn behouden; van de **1168** eindpaden in R zijn alleen de vier bedoelde bestanden aanvullend veranderd. De code-/testhashes, onderbouw-bron-PDF, `.env`-hashes, HEADs en staged diffs blijven gelijk. `git diff --check` en de afzonderlijke whitespacecontrole van de verslagaanvulling zijn geslaagd.
- De **13 PHP-tests, 33 frontendcombinaties, 363 roosterselecties, TypeScript-controle en build** uit run 1 zijn voor deze assetwijzigingen niet herhaald. De eerder vastgelegde resultaten blijven de codecontrole voor ongewijzigde code.
- Geen volledige formulier-/beheerinteractie of HTTP-controle op R uitgevoerd. De bestaande WAMP-site `onderwijsformulier.test` verwijst volgens run 1 naar O en geldt niet als bewijs voor R. Er is in run 2 geen nieuwe server gestart; WAMP-, hosts-, `.env`- en serverconfiguratie zijn niet gewijzigd. Ochtendvarianten 3–5 zijn niet inhoudelijk opnieuw beoordeeld of gewijzigd. Geen databasewijzigingen, boekingsmutaties, mails, staging, commit, push, merge, deployment, seeder of import uitgevoerd.

### Status van de drie oorspronkelijke defecten

| Defect | Stand na run 2 in R |
|---|---|
| Bovenbouw had vijf standaarden en slechts twee keuzes | In run 1 hersteld naar vier standaarden plus één van drie keuzes; alle code-/testwijzigingen behouden. |
| Tweegroepen-ochtendrooster had onjuiste tijden en activiteitenverdeling | Goedgekeurd PDF/PNG-paar byte voor byte overgenomen; inhoud en bron-/bestemmingshashes gecontroleerd. |
| Onderbouw Minecraft-Programmeren, tien groepen, miste de PNG | Preview uit pagina 1 van de bestaande PDF gemaakt; pad, leesbaarheid, tien groepen en overeenkomst gecontroleerd. |

Alle drie de defecten zijn lokaal in de herstelworktree hersteld. De gezamenlijke functionele controle en publicatie staan nog open.

### Aanvullende diff en volledige eindstatus

Aanvullend op run 1 zijn exact deze vier bestanden veranderd:

| Bestand in R | Aanvullende wijziging in run 2 |
|---|---|
| `public/assets/booking/roosters/pdf/po_ochtend_standaard_2.pdf` | Binaire vervanging: 106315 → 83817 bytes; goedgekeurde kopie uit O. |
| `public/assets/booking/roosters/images/po_ochtend_standaard_2.png` | Binaire vervanging: 65435 → 105387 bytes; goedgekeurde kopie uit O. |
| `public/assets/booking/roosters/images/vo_onder_mp_10.png` | Nieuw: 49628 bytes; technische PDF-render met alleen de buitenmarges afgesneden. |
| `docs/current-program-roster-repair.md` | Bestaand ongetrackt run-1-verslag aangevuld met deze run-2-resultaten en overdracht. |

Volledige `git status -sb` in R:

```text
## fix/current-program-roster-consistency
 M public/assets/booking/roosters/images/po_ochtend_standaard_2.png
 M public/assets/booking/roosters/pdf/po_ochtend_standaard_2.pdf
 M scripts/tests/LegacyBookingMapperTest.php
 M src/Booking/BookingProgramConfig.php
?? docs/current-program-roster-repair.md
?? public/assets/booking/roosters/images/vo_onder_mp_10.png
?? scripts/tests/BookingUpperSchoolProgramRegressionTest.php
?? scripts/tests/bookingUpperSchoolModulesTest.mjs
```

Volledige `git status -sb` in O, gelijk aan de start van run 2:

```text
## feature/calendar-booking-clickability...origin/main
 M public/assets/booking/roosters/images/po_ochtend_standaard_2.png
 D public/assets/booking/roosters/images/po_skd_6.png
 M public/assets/booking/roosters/pdf/po_ochtend_standaard_2.pdf
 M resources/css/admin/calendar.css
 M resources/js/admin/components/calendar/DashboardCalendarOverview.vue
 M resources/js/admin/components/calendar/DashboardCalendarOverviewDayCell.vue
 M scripts/tests/DashboardCalendarOverviewFrontendContractTest.php
 M scripts/tests/blockedDayPlanningBrowserTest.mjs
?? docs/GeoFort_Herstel_Huidige_Versie_3_Codex_Runs.md
?? docs/GeoFort_Run_2_Roosterbestanden_Herstellen.md
```

### Concrete overdracht naar run 3

1. Beoordeel de gezamenlijke diff vanaf `c7ac7d259501186602dfb539d30d90d004467d8d`, inclusief de nieuwe tests, dit verslag en de drie assets. Gebruik de vastgelegde hashes om behoud en de bedoelde releasebestanden te controleren. De inspectiemappen blijven buiten de release.
2. Voer de gezamenlijke release-/buildcontrole uit op R en hergebruik de geslaagde run-1-resultaten voor ongewijzigde code. Herhaal alleen gerichte codetests wanneer nieuwe codewijzigingen of concrete afwijkingen dat nodig maken. Leg de bestaande Vite-waarschuwing over `form-heightlines.png` opnieuw vast als die blijft bestaan.
3. Koppel voor browsercontrole aantoonbaar een tijdelijke lokale server aan **R/public**, zonder de bestaande WAMP/vhostconfiguratie te wijzigen. Controleer in het echte publieke formulier en het beheerpaneel de bovenbouw met vier standaarden en elk van de drie keuzes, inclusief Stop-de-Klimaat-Klok als keuze en de bijbehorende roostervoorbeelden/PDF-links.
4. Controleer in diezelfde checkout PO/ochtend op woensdag met **40 leerlingen**: twee groepen, geen keuze, het gecorrigeerde rooster en de PDF. Controleer onderbouw/dag/Minecraft-Programmeren met **151 en 160 leerlingen**: tien groepen, zichtbare nieuwe PNG en bijbehorende PDF. Leg browserresultaten, leesbaarheid bij de gebruikte schermgroottes en eventuele beperkingen vast.
5. Productiekoppelingen en daar aanwezige bestanden zijn nog niet gecontroleerd. Dat blijft een afzonderlijke controle vóór publicatie. Staging, commit en publicatie volgen alleen op Kevins latere expliciete opdracht; run 2 voert die niet uit.

## Run 3 — uitgevoerd op 20 september 2026

De gezamenlijke correctie is lokaal functioneel gecontroleerd. De ontbrekende browsergevallen zijn uitgevoerd met echte Vue, PHP, validatie en SQL op de herstelworktree. Tijdens de visuele eindcontrole kwamen aanvullende, concrete fouten in de bovenbouw-klokroosters aan het licht. Die zijn in deze run hersteld en opnieuw gecontroleerd. Er is geen vierde ontwikkelfase nodig voor de aangetroffen lokale fouten. Productie is niet onderzocht of gepubliceerd.

### Werkbasis, review en behoud

- Werkpad R, branch `fix/current-program-roster-consistency`, HEAD en basiscommit `c7ac7d259501186602dfb539d30d90d004467d8d` zijn behouden. AGENTS.md, het volledige herstelverslag en `O/docs/GeoFort_Run_3_Eindcontrole_Huidige_Versie.md` zijn gelezen. Geen onderliggende projectinstructies gevonden. Het aangeleverde run-document blijft in O en hoort niet bij de commitset.
- De beginmanifesten omvatten **1167 paden in O en 1168 in R**, inclusief alle gevolgde en niet-genegeerde nieuwe bestanden, ontbrekende bestanden, branch, HEAD, volledige status, binaire staged/unstaged diff en omgevingsbestandhash. De bestanden van R kwamen volledig overeen met het opgeslagen eindmanifest van run 2. Bewijs: `R/local-backups/run3-inspection/before.json` en de bijbehorende patches.
- De gezamenlijke tekstuele diff en alle nieuwe tests zijn inhoudelijk beoordeeld. De productiecode blijft beperkt tot de verplaatsing van `Stop-de-Klimaat-Klok` naar de drie bovenbouwkeuzes. Vier standaarden, precies één keuze en vijf rondes blijven centraal bepaald. Publieke configuratie, gedeelde frontendhelpers, PHP-validators, beheer, roosterresolver en mailopbouw gebruiken diezelfde configuratie.
- De mappertest behoudt de geldige historische klokkeuze en toetst de waarschuwing voor een tegenwoordig standaardonderdeel nu met `Earth-Watch`. De bestaande controles voor andere sectoren, historische keuzes en gegevensbehoud zijn behouden. De mapper zelf is niet gewijzigd.
- De vier code-/testbestanden van run 1 houden hun in run 2 vermelde hashes. Ook de drie goedgekeurde assets van run 2 houden exact hun opgegeven SHA256. Deze zijn met het beginmanifest vergeleken en niet opnieuw gekopieerd of gerenderd. De onderbouw-bron-PDF blijft eveneens gelijk.
- Geen aanpassing aan leerlinggrenzen, groepsbereiken, prijzen, begeleidersregels, capaciteit, kalendergedrag, voorwaarden, catering of downloadfunctionaliteit. Alleen de hieronder beschreven extra roosterbestanden, een gerichte assetregressietest en dit verslag zijn aanvullend veranderd.

### Extra gevonden roosterfouten en concrete correctie

Een succesvolle resolver, bestandsnaam of HTTP 200 bleek onvoldoende: bij bovenbouw/klok/40 vermeldde de interface drie groepen, terwijl `vo_boven_sdk_3.png` zichtbaar zeven groepen bevatte. De eerste browseruitvoer van dit geval is daarom **bewijs van de fout vóór correctie**, geen geslaagde visuele eindcontrole. De gerichte inspectie van de hele klokreeks gaf:

| Bestandsnummer | Groepen in oorspronkelijke PNG | Groepen in oorspronkelijke PDF | Definitieve groepen in beide |
|---|---:|---:|---:|
| 3 | 7 | 3 | 3 |
| 4 | 8 | 4 | 4 |
| 5 | 9 | 5 | 5 |
| 6 | 10 | 6 | 6 |
| 7 | 3 | 7 | 7 |
| 8 | 4 | 10 | 8 |
| 9 | 5 | 9 | 9 |
| 10 | 6 | 8 | 10 |

De PDF die tien groepen bevatte had bovendien bij **groep 10, 11:15–12:00** `Minecraft-Programmeren`. Die groep had de vier standaardactiviteiten verder al eenmaal en miste de gekozen `Stop-de-Klimaat-Klok`. De bestaande programmaregels bepalen de correctie dus eenduidig.

- `vo_boven_sdk_8.pdf` is nu een byte-identieke kopie van de oorspronkelijke `vo_boven_sdk_10.pdf`, die het juiste achtgroepenrooster bevatte. SHA256: `B4815F5B9A0DE08DA272A5AA9F51E80D30787F4F3495E41707253CFF055326D9`.
- `vo_boven_sdk_10.pdf` komt uit de oorspronkelijke `vo_boven_sdk_8.pdf` (bronhash `2499C1C2CDE01CC5CA3527CE4F37741B29DAAB6598F6F55FE2C6E9396A52C6C1`). Alleen de genoemde cel is gecorrigeerd. Een incrementele PDF-update vervangt contentobject 6: de bestaande kloktekst, tekstkleur en celkleur zijn hergebruikt, met passende centrering binnen dezelfde cel. Fonts, pagina, tabel, tijden en alle andere tekeninstructies blijven behouden. De oorspronkelijke PDF-bytes zijn als volledig voorvoegsel behouden. Nieuwe SHA256: `CC1FEA143A6795CE31254EB858045493C5C22F35FAA18D9D5841ACD9F5375AE4`.
- De Windows-paginarenders vóór en na deze celcorrectie zijn pixel voor pixel vergeleken: **7452 gewijzigde pixels**, uitsluitend binnen `x=1486–1623, y=404–457`, de bedoelde cel. **Alle overige pixels zijn identiek.** De gecorrigeerde PDF opent ook in de Chrome-PDF-viewer.
- De acht PNG's `vo_boven_sdk_3.png` t/m `_10.png` zijn technisch uit pagina 1 van de inhoudelijk passende PDF gemaakt, met dezelfde Windows.Data.Pdf-renderer als run 2 (versie `10.0.26100.8875`, `DestinationWidth=1024`). Alleen wit/vrijwel wit papier buiten de inhoud is afgesneden: iedere verwijderde RGB-component is minimaal 254; zes pixels buitenmarge, geen schaling, 120 dpi metadata. Alle bewaarde pixels zijn na opnieuw openen exact gelijk aan de betreffende volledige render. De oorspronkelijke PDF's 3–7 en 9 blijven ongewijzigd. Geen generatieve afbeelding, nieuwe dependency of applicatiebibliotheek gebruikt.

| PNG | Afmetingen | Bytes | Definitieve SHA256 |
|---|---|---:|---|
| `vo_boven_sdk_3.png` | 1512 × 803 | 68930 | `BE2830ADE8B9F59E69ACC7BA48BE762AA570CE28AAE91A9184A569BBA6988DCF` |
| `vo_boven_sdk_4.png` | 1525 × 727 | 73231 | `E3CCE0D06233A1D153374A9B7BCE2D8B34116C2EEB171BC0B7B960EDB4AA6348` |
| `vo_boven_sdk_5.png` | 1526 × 614 | 62936 | `F796561662330CCF2003A69AB4146E9E2C44BFABDC59DD1EA60E872BF26C29CA` |
| `vo_boven_sdk_6.png` | 1505 × 638 | 49110 | `8209B7EC617AB416DF022AAA5667E044FC3ECE8FD27FDDFF22023A821CA3A79E` |
| `vo_boven_sdk_7.png` | 1540 × 636 | 51805 | `D9DCD2DD15A91D4DE865883CACC240D5CAC4C4B12A9930DFACDA1E80D7D6C885` |
| `vo_boven_sdk_8.png` | 1526 × 736 | 53332 | `7CCCA4AC46624A9703DF49A1C6EDA93EA499E3D81244DB5FBA90D34A5BDB953B` |
| `vo_boven_sdk_9.png` | 1512 × 692 | 51527 | `FFF6350F39972251C59236BED720D3EA55193ED069899ECD8F1B9BA051EF612D` |
| `vo_boven_sdk_10.png` | 1509 × 563 | 45713 | `1B4397408AD65E10554DB3F7299922717332573B6B4468649462973EB2E27074` |

Bewijs staat in `run3-inspection/clock-final-pairs.json`, `clock-pdf-correction.json`, `clock-cell-pixel-proof.json`, de `clock-*-render.json`-bestanden, de oorspronkelijke afbeeldingen en de volledige paginarenders. De bestaande klokregistraties blijven naar dezelfde publieke paden verwijzen; er is geen databasecorrectie nodig voor deze lokale bestandsreparatie.

### Aantoonbare, geïsoleerde browserpreview

- Tijdelijke URL: **`http://127.0.0.1:8763/`**, documentroot **`R/public`**. PHP 8.3.28, echte applicatie-entrypoints en een tijdelijke router buiten `public`. De PHP-listener en de headless Chrome-debuglistener waren uitsluitend aan `127.0.0.1` gebonden. `onderwijsformulier.test` is niet als herstelbewijs gebruikt.
- Bootstrap, Dotenv, Connector, sessies, kalender-/beschikbaarheidsdiensten en mailtransport zijn vooraf gelezen. De bootstrap vereist een `.env`-bestand; R had er geen. Alleen tijdens beide previews bestond daar een **leeg** tijdelijk bestand. Alle effectieve instellingen kwamen uit de tijdelijke proces/routerconfiguratie in `$_ENV`, `$_SERVER` en de procesomgeving, vóór de immutable Dotenv-load. Het bestaande `.env` in O is niet gewijzigd.
- Twee afzonderlijke disposable databases zijn achtereenvolgens gebruikt: `geofort_run3_disposable_test_6cf73467` voor de oorspronkelijke browsergevallen/beheer en `geofort_run3_disposable_test_72e1bb67` voor de noodzakelijke klokhercontrole. Beide op de gecontroleerde lokale MySQL 8.4.7, `127.0.0.1:3306`. Voor schemaopbouw zijn serveridentiteit, poort en daadwerkelijk geselecteerde databasenaam gecontroleerd.
- Elke preview had een eigen tijdelijke databasegebruiker met uitsluitend `SELECT, INSERT, UPDATE, DELETE` op zijn eigen testdatabase. `SHOW GRANTS` is vastgelegd. De echte Connector werd vóór ieder applicatieverzoek gecontroleerd met `DATABASE()`, `@@port` en `CURRENT_USER()`; de bootstrapconfiguratie is daarnaast via HTTP gecontroleerd. Geen verbinding met de inhoud van `geoform_db`, `school_db` of productie voor deze tests, geen dump/import of productietunnel.
- Hergebruikt synthetisch testschema, 44 fictieve roosterregistraties volgens de reeds vastgestelde bereiken, één fictieve schoolboeking en één nieuw fictief beheeraccount per database. Geen gekopieerde gebruikers of boekingen. In de eerste database: één gewone succesvolle login, een ongewijzigde eerste opslag en twee echte programmawijzigingen met twee historyregels. Aantallen, school/contact, status, bezoekdatum, catering en prijsweergave bleven gelijk. Geen prijssnapshots of publieke aanvragen toegevoegd.
- `PhpMailerMailer` werd uitsluitend in de preview vervangen door een fake implementatie van `MailInterface`. Dat is via reflectie in de werkende PHP-preview bewezen. Daarnaast waren `mail`, `fsockopen`, `pfsockopen`, `stream_socket_client` en `curl_exec` uitgeschakeld. Geen SMTP en geen aanroep van de fake verzendmethode tijdens de browsergevallen. De bestaande zes mailrendercontroles blijven afzonderlijk hergebruikt bewijs.
- De preview gebruikte `APP_ENV=production` om de echte productiebuild via het manifest te laden, met bovengenoemde testdatabase en fake transport. De tijdelijke router vertaalde uitsluitend de normale canonieke applicatieredirects naar de loopback-URL. Authenticatie, sessiecontrole en CSRF-code bleven intact; de sessiecookie hield `Secure`, `HttpOnly` en `SameSite=Lax`.
- HTTP-bewijs: de geladen programmaclass komt uit `R/src/Booking/BookingProgramConfig.php`; de configuratie geeft vier standaarden en drie keuzes. Herstelde assets zijn via HTTP opgehaald en op MIME, PNG-/PDF-signatuur en volledige SHA256 vergeleken. Ook daadwerkelijk geladen browserscripts zijn tegen het manifest en de lokale bytes gecontroleerd:

| Geladen bestand uit `R/public/build` | SHA256 |
|---|---|
| `.vite/manifest.json` | `277C351CEE9E33F98999F7EF2B6F77255BC162141FE8DF359E7704250A366E9B` |
| `assets/booking-BPXhrTBE.js` | `130DF2D310E8AEB54B7438B9B8915E36074FD5DDAD379023BE543E06C5E8EA85` |
| `assets/admin-1D6LXCD6.js` | `7C63D869EEABA1BD97B2CFC3FD0A504BDDFFC1E6FCA7727A482FB9CEE841D0DA` |

Bewijs: `run3-inspection/{fixture-proof,http-proof,verification,request-guards}.json*` en dezelfde bewijssoorten in `run3-clock-recheck/`. De tweede preview gebruikte exact hetzelfde manifest; er is niet opnieuw gebouwd na de assetcorrecties.

### Definitieve browserresultaten

Alle API's hieronder zijn echte PHP-/database-antwoorden; er zijn geen API-mocks gebruikt. Datum- en selectievelden, aantallen, teruggaan, beheerreview en downloadlinks zijn via de bestaande bediening getest. Er is geen echte aanvraag verstuurd.

| Geval | Resultaat |
|---|---|
| Bovenbouw/dag/HAVO 4/Crisismanagement, 40 leerlingen, maandag 8 februari 2027 | Vier standaarden en de gekozen vijfde activiteit; drie groepen; bijpassende PNG en geopende PDF. Keuze behouden bij teruggaan naar het programmaveld en opnieuw verdergaan. |
| Bovenbouw/dag/HAVO 4/Minecraft-Programmeren, 40 leerlingen | Dezelfde controles geslaagd, met `vo_boven_mp_3`. |
| Bovenbouw/dag/HAVO 4/Stop-de-Klimaat-Klok, 40 leerlingen | Na de aanvullende correctie drie groepen in interface, PNG én PDF; klok alleen als keuze. Het oude beeldresultaat is vervangen door `run3-clock-recheck/clock-fixed-3-*`. |
| PO/ochtend/regulier/groep 5, woensdag 10 februari 2027, 40 leerlingen | Twee roostergroepen, vijf ochtendonderdelen, geen losse of achtergebleven dagkeuze. PNG/PDF hebben de goedgekeurde hashes. Alle acht bevestigde tijdvakken en beide groepsvolgorden komen overeen, inclusief pauze 10:55–11:10 en vertrek 12:10–12:15. |
| VO-onderbouw/dag/HAVO 2/Minecraft-Programmeren, **151** leerlingen | Tien groepen, werkelijk geladen PNG van 1536 × 570, juiste geopende tiengroepen-PDF, geen fallback. Afzonderlijk POST-/responsebewijs met 151 vastgelegd. |
| Dezelfde onderbouwselectie, afzonderlijk **160** leerlingen | Dezelfde tiengroepen-PDF en PNG; afzonderlijk POST-/responsebewijs met 160. |
| Beheer, elk van de drie bovenbouwkeuzes | Normale login vanaf de afgeschermde dashboardroute; vijf wizardstappen, correcte drie keuzes, review, terug/vooruit met behouden keuze en normale opslag via het actiegebonden CSRF-header. Opnieuw opgehaalde boeking bevat telkens de gekozen key. De door beheer ontvangen centrale moduleconfiguratie bevat vier standaarden; de wizard zelf toont de keuzemodules. |
| Gerichte hercontrole hele gecorrigeerde klokreeks | **40, 51, 66, 81, 101, 121, 131 en 151 leerlingen** selecteren achtereenvolgens **3–10 groepen**. Alle acht PNG's laden en alle acht PDF-links openen; bestandstype, hash, groepenaantal en inhoud komen overeen. |

Authenticatie zonder login leidde naar de loginpagina. Een beheer-POST zonder CSRF-token gaf **403 `INVALID_CSRF`**. De drie normale beheeropslagen gaven HTTP 200; geen regeloverride nodig. Voor de publieke pagina bestaat geen aparte volgende/vorige-wizard: teruggaan is daar getest door het eerdere programmaveld opnieuw te openen/bevestigen en naar de module/roosterweergave terug te keren.

Visueel gecontroleerd op **1440 × 1100** en **390 × 844**; de native PDF-viewer is ook vergroot bekeken. Tabellen en randen zijn volledig. Op smalle schermen blijven de pagina en downloadknop binnen de viewport, maar vooral de tiengroepentabellen zijn klein en vragen vergroting via de PDF. Dit is een bestaande presentatiebeperking, geen ontbrekend of afgesneden asset. De overige ochtendvarianten 3–5 en de volledige oorspronkelijke inventaris van 92 roosters zijn niet alsnog volledig visueel doorgelicht.

Geen onverwachte JavaScript- of PHP-fouten in de geslaagde definitieve flows. Bekende ongerelateerde HTTP 404: `build/assets/assets/images/form-heightlines.png`; daarnaast vroeg Chrome een ontbrekende `favicon.ico` op. De bewuste CSRF-afwijzing is apart als verwacht resultaat vastgelegd. Screenshots en JSON bevatten uitsluitend fictieve formulier-/boekingsgegevens; geen inlogwachtwoorden of echte contactgegevens.

### Uitgevoerde en hergebruikte tests

- **Hergebruikt:** de 13 geslaagde PHP-tests van run 1, 33 frontendcombinaties, 363 lokale bovenbouwroosterselecties en zes fake mails. De bijbehorende code, fixtures en opgeslagen run-2-manifesten zijn gelijk gebleven. Behoud van opgeslagen keuzes, leerling-/begeleidersaantallen en prijsdetails blijft gedekt. Later opgebouwde mails tonen bewust de actuele vier standaarden; reeds verzonden mails worden niet gewijzigd.
- **Nieuwe gerichte regressie:** `scripts/tests/BookingUpperSchoolRosterAssetsTest.php`. Leest de actieve PDF-pagina, inclusief de incrementele correctie; controleert groepskoppen 1–N, de vijf verwachte activiteiten met elk N voorkomens over de tabel, afwezigheid van Minecraft in de klokreeks en de hashes/type/afmetingen van de visueel en technisch gecontroleerde PNG's. De beperkte PDF-reader ondersteunt deze bestaande exports en faalt bij een niet-ondersteunde structuur. Tegen de oorspronkelijke assets in O faalt de test aantoonbaar op de verkeerde driegroepen-PNG; tegen R slaagt hij voor alle acht paren. Geen database, `.env` of mail geladen.
- **PHP 8.3-syntaxcontrole geslaagd:** `BookingProgramConfig.php`, `LegacyBookingMapperTest.php`, `BookingUpperSchoolProgramRegressionTest.php` en de nieuwe `BookingUpperSchoolRosterAssetsTest.php`.
- **Eén gezamenlijke TypeScript-/buildcontrole:** vanuit O `node node_modules/vue-tsc/bin/vue-tsc.js -b local-backups/worktrees/current-program-roster-consistency/tsconfig.json`, daarna vanuit R `node C:/wamp64/www/GeoFortForm4/node_modules/vite/bin/vite.js build`. Dit zijn de aanwezige executables van de bestaande buildstappen; beide exit 0. Vite 7.3.6, 2016 modules, build 15,05 seconden. Geen dependencies of lockfiles veranderd. De bekende waarschuwing over `form-heightlines.png` blijft afzonderlijk open. Dezelfde build is voor beide previews gebruikt.
- **Gerichte browserhercontrole** na de uitsluitend binaire roosterwijzigingen: alle acht klokparen. De overige geslaagde browsergevallen en beheercontroles zijn hergebruikt; geen brede suite of tweede build uitgevoerd.
- **Whitespace en behoud:** definitieve `git diff --check` en controle van de nieuwe tekstbestanden geslaagd. De eindmanifestcontrole vergelijkt alle oorspronkelijke bestanden van O en alle bestanden van R; alleen de twaalf bedoelde aanvullingen van run 3 mogen afwijken. HEADs, branches, staged diffs, alle run-1/2-bestanden behalve de bewuste verslagaanvulling en de oorspronkelijke `.env` blijven behouden.

### Opruiming en exacte commitset

Beide disposable databases en hun beperkte gebruikers zijn verwijderd. De eigen PHP-/Chrome-processen zijn gestopt en de loopbackpoorten gecontroleerd als gesloten. Beide lege tijdelijke `.env`-bestanden, browserprofielen, sessies, runtimecredentials en tijdelijke uitvoerhulpmiddelen zijn opgeruimd. Bestaande WAMP-services, hosts/vhosts, `geoform_db`, `school_db` en ongerelateerd werk blijven behouden. Geschoonde controle-uitvoer blijft genegeerd onder `R/local-backups/run3-inspection/` en `R/local-backups/run3-clock-recheck/`; eerdere inspectiemappen blijven intact.

**Exact 19 bestanden voor een latere gezamenlijke commit:** de oorspronkelijke acht herstelbestanden, tien aanvullend gecorrigeerde klokassets en één gerichte regressietest. Alle aanvullingen volgen uit de hierboven vastgelegde visuele defecten.

```text
docs/current-program-roster-repair.md
public/assets/booking/roosters/images/po_ochtend_standaard_2.png
public/assets/booking/roosters/images/vo_boven_sdk_3.png
public/assets/booking/roosters/images/vo_boven_sdk_4.png
public/assets/booking/roosters/images/vo_boven_sdk_5.png
public/assets/booking/roosters/images/vo_boven_sdk_6.png
public/assets/booking/roosters/images/vo_boven_sdk_7.png
public/assets/booking/roosters/images/vo_boven_sdk_8.png
public/assets/booking/roosters/images/vo_boven_sdk_9.png
public/assets/booking/roosters/images/vo_boven_sdk_10.png
public/assets/booking/roosters/images/vo_onder_mp_10.png
public/assets/booking/roosters/pdf/po_ochtend_standaard_2.pdf
public/assets/booking/roosters/pdf/vo_boven_sdk_8.pdf
public/assets/booking/roosters/pdf/vo_boven_sdk_10.pdf
scripts/tests/BookingUpperSchoolProgramRegressionTest.php
scripts/tests/BookingUpperSchoolRosterAssetsTest.php
scripts/tests/LegacyBookingMapperTest.php
scripts/tests/bookingUpperSchoolModulesTest.mjs
src/Booking/BookingProgramConfig.php
```

Geen schema- of datamigratie nodig voor deze lokale code-/bestandsset. Buildoutput, inspectiescripts, credentials, fixtures, aangeleverde runopdrachten en kalenderwijzigingen horen niet bij deze commitset. Voorstel commitboodschap: **`Fix current upper-school program and timetable assets`**. Niets gestaged, gecommit, gepusht, gemerged of gedeployd.

### Resterende controles vóór en na publicatie

Deze controles zijn **nog niet op productie uitgevoerd**. Het lokale bestand-/codeherstel bewijst niet dat productie dezelfde registraties heeft. Een productieafwijking moet afzonderlijk worden beoordeeld; 92 is geen verplicht productieaantal.

1. Vóór publicatie: review de exacte 19 bestanden en de binaire klokcorrecties. Controleer de gebruikte deployment-root en actuele productieverbinding. Lees uitsluitend het werkelijk gebruikte `roosters`-schema en onderstaande registraties, zonder updates, seeders of imports:

```sql
START TRANSACTION READ ONLY;
SELECT DATABASE() AS database_name, VERSION() AS server_version;
SELECT id, schooltype, programmaduur, keuzemodule,
       leerlingen_min, leerlingen_max, afbeelding, pdf
FROM roosters
WHERE (schooltype = 'bovenbouw' AND programmaduur = 'dag'
       AND keuzemodule IN ('Crisismanagement', 'Minecraft-Programmeren', 'Stop-de-Klimaat-Klok'))
   OR (schooltype = 'primair' AND programmaduur = 'ochtend'
       AND keuzemodule = 'Standaard-Ochtend-Programma-PO'
       AND leerlingen_min <= 40 AND leerlingen_max >= 40)
   OR (schooltype = 'onderbouw' AND programmaduur = 'dag'
       AND keuzemodule = 'Minecraft-Programmeren'
       AND leerlingen_min <= 160 AND leerlingen_max >= 151)
ORDER BY schooltype, programmaduur, keuzemodule, leerlingen_min, leerlingen_max, id;
ROLLBACK;
```

2. Controleer exacte modulekeys en voor ieder toegestaan leerlingenaantal precies één passende registratie: bovenbouw **40–50 → 3**, **51–65 → 4**, **66–80 → 5**, **81–100 → 6**, **101–120 → 7**, **121–130 → 8**, **131–150 → 9**, **151–160 → 10**; geen gaten of overlap. PO/ochtend/40 moet precies het tweegroepenpaar kiezen. Onderbouw/programmeren/151 én 160 moeten precies het tiengroepenpaar kiezen. De formuliergrens blijft 40; een eventueel ruimer opgeslagen roosterinterval introduceert geen nieuwe formuliergrens.
3. Controleer de exacte opgeloste paden en hoofdletters, met prefix `/assets/booking/roosters/`: bovenbouw `images/vo_boven_cmg_{3..10}.png` + `pdf/vo_boven_cmg_{3..10}.pdf`, overeenkomstig `vo_boven_mp_{3..10}` en `vo_boven_sdk_{3..10}`; PO `images/po_ochtend_standaard_2.png` + `pdf/po_ochtend_standaard_2.pdf`; onderbouw `images/vo_onder_mp_10.png` + `pdf/vo_onder_mp_10.pdf`. De accolades staan hier voor elk afzonderlijk groepsnummer. Vergelijk inhoud/groepskoppen, niet alleen namen. `po_skd_6.png` blijft los van de gebruikte `po_sdk_6.png`.
4. Na Kevins afzonderlijke commit-/publicatieopdracht: publiceer de gecontroleerde code en alle bijbehorende assets samen, volgens de bestaande deploymentprocedure. Controleer daarna via HTTP de publieke configuratie, geladen releasebundel en gewijzigde PNG-/PDF-hashes, inclusief de acht klokpreviews en de twee klok-PDF's. Controleer eventuele assetcache zodat de oude verwisselde afbeeldingen niet blijven verschijnen.
5. Na publicatie: doorloop de drie bovenbouwkeuzes, PO/ochtend op een beschikbare woensdag/40 en onderbouw/programmeren/151 en 160 zonder een echte aanvraag te versturen. Open de PDF's en controleer in beheer via normale login de drie keuzes zonder een echte boeking te wijzigen. Beoordeel de tiengroepen-PDF op vergroting en de gecorrigeerde groep-10-cel. Bestaande mailconfiguratie en daadwerkelijke aflevering zijn geen onderdeel van het lokale browserbewijs; controleer de configuratie uitsluitend lezend, zonder testmail naar echte ontvangers.

### Volledige git-eindstatus

Beide staged diffs zijn leeg. Volledige `git status -sb --untracked-files=all` in R:

```text
## fix/current-program-roster-consistency
 M public/assets/booking/roosters/images/po_ochtend_standaard_2.png
 M public/assets/booking/roosters/images/vo_boven_sdk_10.png
 M public/assets/booking/roosters/images/vo_boven_sdk_3.png
 M public/assets/booking/roosters/images/vo_boven_sdk_4.png
 M public/assets/booking/roosters/images/vo_boven_sdk_5.png
 M public/assets/booking/roosters/images/vo_boven_sdk_6.png
 M public/assets/booking/roosters/images/vo_boven_sdk_7.png
 M public/assets/booking/roosters/images/vo_boven_sdk_8.png
 M public/assets/booking/roosters/images/vo_boven_sdk_9.png
 M public/assets/booking/roosters/pdf/po_ochtend_standaard_2.pdf
 M public/assets/booking/roosters/pdf/vo_boven_sdk_10.pdf
 M public/assets/booking/roosters/pdf/vo_boven_sdk_8.pdf
 M scripts/tests/LegacyBookingMapperTest.php
 M src/Booking/BookingProgramConfig.php
?? docs/current-program-roster-repair.md
?? public/assets/booking/roosters/images/vo_onder_mp_10.png
?? scripts/tests/BookingUpperSchoolProgramRegressionTest.php
?? scripts/tests/BookingUpperSchoolRosterAssetsTest.php
?? scripts/tests/bookingUpperSchoolModulesTest.mjs
```

Volledige `git status -sb --untracked-files=all` in O:

```text
## feature/calendar-booking-clickability...origin/main
 M public/assets/booking/roosters/images/po_ochtend_standaard_2.png
 D public/assets/booking/roosters/images/po_skd_6.png
 M public/assets/booking/roosters/pdf/po_ochtend_standaard_2.pdf
 M resources/css/admin/calendar.css
 M resources/js/admin/components/calendar/DashboardCalendarOverview.vue
 M resources/js/admin/components/calendar/DashboardCalendarOverviewDayCell.vue
 M scripts/tests/DashboardCalendarOverviewFrontendContractTest.php
 M scripts/tests/blockedDayPlanningBrowserTest.mjs
?? docs/GeoFort_Herstel_Huidige_Versie_3_Codex_Runs.md
?? docs/GeoFort_Run_2_Roosterbestanden_Herstellen.md
?? docs/GeoFort_Run_3_Eindcontrole_Huidige_Versie.md
```
