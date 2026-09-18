# Bestaande boekingen op geblokkeerde dagen

Lokale implementatie en verificatie: 18 september 2026.
Branch: `feature/blocked-day-existing-bookings`.
Uitgangscommit: `bc14330b38cb8bbf141fcc8dbc5b5d6edcb0b3f0`.
Deze wijziging is niet gecommit, gepusht of gedeployd. Publicatie van de
uitgangscommit bewijst geen deployment op de server.

## Aangetroffen uitsluiting en gegevensroute

De kalenderquery leverde boekingen op gesloten dagen al aan. Het verlies zat
in de verwerking daarna:

- Het Vue-maandoverzicht sloot geblokkeerde dagen uit de samenvatting uit.
  De kalendercel toonde door een `v-else-if` alleen de blokkade, niet de
  boekingen. In beheer was het bekijken van actieve planning bovendien
  gekoppeld aan toestemming om opnieuw te blokkeren.
- De gedeelde capaciteitsberekening telde leerlingen en boekingen wel, maar
  gebruikte alleen boekbare dagen voor capaciteit en targets. Daardoor kon
  dezelfde boeking na blokkeren een andere noemer krijgen. Ook weekenden en
  niet-boekbare programmaweekdagen met bestaande planning vielen weg.
- De eerste lokale uitwerking bepaalde kwalificerende planning na het
  sectorfilter. De aanvullende regressie bewees dat daardoor een gesloten
  PO-bezoekdag bij een VO-filter verdween, terwijl dezelfde vrije dag bleef
  meetellen. De daggrondslag wordt nu vóór sectorfiltering bepaald. Ook een
  gesloten dag met nul geselecteerde boekingen blijft in de dagsnapshots
  voor officiële targets en scenario's aanwezig.
- Het gewone weekdagrapport bevatte alleen maandag tot en met vrijdag.
- Kalendercellen gebruikten de standaardcapaciteit, terwijl dagdetails en
  analytics expliciete daginstellingen al toepasten.
- De maandcache bleef bestaan na terugkeer uit boekingsdetails. Een aparte
  browserregressie reproduceerde dat oude aantallen zichtbaar bleven zonder
  een nieuwe overzichtsaanvraag. Bij activering van het overzicht wordt die
  cache nu ongeldig gemaakt en opnieuw gelezen, ook voor verplaatsingen
  tussen maanden.

Onderzochte routes:

| Onderdeel | Route en verwerking | Bevinding / wijziging |
| --- | --- | --- |
| Overzichtsagenda | `calendar/overview.php` → `DashboardCalendarOverviewAction` → `DashboardCalendarOverviewService` → `DashboardCalendarOverviewSqlRepository` → Vue-overzicht/cel | SQL groepeert rechtstreeks op datum, status en programma; geen join met blokkades of historie. Service voegt dagcapaciteit toe, Vue toont blokkade én planning. |
| Beheer en dagdetails | `calendar/month.php` → `DashboardCalendarAction` → `DashboardCalendarService` → `DashboardCalendarSqlService`, `BookingCalendarSqlService`, daginstellingen en blokkades | Details bevatten bestaande boekingen al. De overzichtscel opent nu dezelfde beveiligde leesroute en echte detailcomponent. Beheerselectie voor lezen is losgemaakt van mutatietoestemming. |
| Aanvragen en boekingsdetails | `requests/index.php`, `requests/show.php` → `DashboardBookingSqlService`, `DashboardBookingDetailSqlService` | Geen uitsluiting door `disabled_dates`; dezelfde boekingen blijven opvraagbaar. |
| Dashboardplanning | `overview.php` → `DashboardOverviewSqlRepository` | Bestaande statusfilters; geen uitsluiting door blokkades. |
| Analytics | `requests/analytics.php` → `BookingAnalyticsService` → `BookingAnalyticsRepository`, `BookingAdvancedAnalyticsCalculator`, `CapacityTargetAnalyticsCalculator` | Periode, populatie en programma bepalen kwalificerende planning voor de daggrondslag. Sector selecteert de werkelijke aantallen. Eén gedeelde dagelijkse grondslag voor capaciteit en officiële targets; scenario gebruikt dezelfde dagvlag. |
| Omzet | `requests/revenue.php`, `revenue-bookings.php`, `revenue-export.php` → `BookingRevenueReportService` / `BookingRevenueReportSqlRepository` | Geen blokkadefilter. Alleen laatste prijssnapshot per boeking; definitieve en potentiële omzet blijven gescheiden. |
| Aanvragenexport | `requests/export-csv.php`, `export-summary.php`, `export-metadata.php` → `BookingExportSqlRepository` | Geen blokkadefilter. Onderwijsselecties worden apart verwerkt; geen nieuwe joins toegevoegd. |
| Publieke beschikbaarheid | `BookingAvailabilityService::assertDateIsValid` en bestaande datum-/programmavalidatie | Ongewijzigd: aanwezigheid van planning maakt een gesloten dag niet boekbaar. |

De genoemde API-routes liggen onder `/api/admin/`, behalve de publieke
validatieservice. API-entrypoints bevatten geen nieuwe SQL.

## Regels na de wijziging

1. `available` blijft de bestaande beschikbaarheidsregel. De nieuwe
   `countsForCapacity` is waar als de datum gewoon beschikbaar is, of als
   binnen de geselecteerde datum-/programma-/statusscope actieve planning op
   die datum bestaat, ongeacht de sector. Een sectorfilter verandert de
   geselecteerde boekings- en leerlingaantallen, niet de eenmaal meetellende
   capaciteit-/targetdag. Een set van datums en één kalenderlus voorkomen
   dubbeltelling.
2. `Definitief` telt mee volgens de bestaande berekening. `In optie` telt mee
   in de planningpopulatie, maar niet in de populatie alleen-definitief.
   Opties reserveren nog steeds geen technische boekingsplek in de
   beschikbaarheidsvalidator; hun omzet blijft potentieel.
3. `Afgewezen` activeert nooit een gesloten dag. De expliciete weergave
   **Alle statussen** behoudt haar bestaande mogelijkheid om afgewezen
   records en hun aantallen te tonen; die records krijgen geen nieuwe
   omzet of actieve bezoekdag. De standaardsamenvatting van actieve planning
   sluit ze uit.
4. Per meetellende datum gelden de volledige daglimieten uit de bestaande
   capaciteitprovider en daginstellingen. Geen afleiding uit het geboekte
   leerlingenaantal en geen automatische beperking tot één school.
   De bestaande ochtendgrens blijft gelden. `EffectiveDayCapacity` deelt
   deze programmacapaciteit nu tussen analytics en kalenderoverzicht.
5. Handmatige blokkades, vakanties en weekenden worden voor bestaande
   planning hetzelfde behandeld, inclusief weekenden zonder seedrecord.
   Inhoudelijke programmagrenzen en expliciete daginstellingen blijven gelden.
6. Het officiële target wordt op bezoekdatum gekozen. Ontbrekende targets,
   nulwaarden, lopende maanden t/m vandaag en toekomstige beoordelingen
   behouden hun bestaande behandeling. Er wordt geen target verzonnen.
7. Na afwijzen of verplaatsen van de laatste kwalificerende boeking verdwijnt
   een nog gesloten datum uit de capaciteit-/targetgrondslag. Een gewone
   beschikbare datum blijft volgens de bestaande regels meetellen. Alleen
   wegfilteren op sector laat de datum niet verdwijnen.

De bestaande API-velden `availableDays` en `evaluatedAvailableDays` bevatten
nu deze daggrondslag. De UI noemt ze daarom **Dagen in grondslag**. Het
aparte snapshotveld `available` blijft beschikbaarheid aangeven.

## Rekenvoorbeelden uit synthetische fixtures

Testtarget: vanaf 1 maart 2027 100 leerlingen / 1 boeking per dag; vanaf
10 maart 120 leerlingen / 1,5 boeking per dag. Geen productiegegevens gebruikt.

| Situatie | Werkelijk | Dagen in grondslag | Capaciteit leerlingen / boekingsplekken | Geldend dagtarget |
| --- | --- | --- | --- | --- |
| 8 maart, één definitieve boeking, vrij of handmatig/vakantie geblokkeerd | 60 leerlingen, 1 boeking, € 654 definitieve omzet | 1 | 160 / 2 | 100 / 1 |
| 8 maart, twee definitieve boekingen op dezelfde geblokkeerde datum | 60 + 40 leerlingen, 2 boekingen | 1 | 160 / 2 | 100 / 1 |
| 8 maart, uitsluitend een optie, populatie planning | 30 leerlingen, € 654 potentieel; € 0 definitief | 1 | 160 / 2 | 100 / 1 |
| Dezelfde optie, populatie alleen-definitief | 0 boekingen | 0 | 0 / 0 | Geen meegeteld dagtarget |
| 10 maart, vakantie, override 100 leerlingen / 1 school | 60 leerlingen ochtendprogramma | 1 | Alle programma's: 100 / 1; ochtendfilter: 80 / 1 | 120 / 1,5 |
| 13 maart, weekend, override 220 leerlingen / 3 scholen | 60 leerlingen | 1 | 220 / 3 | 120 / 1,5 |
| Gesloten datum zonder actieve planning, ook na laatste afwijzing/verplaatsing | Geen actieve boeking | 0 | 0 / 0 | Geen meegeteld dagtarget |
| 29 januari, gesloten met definitieve boeking, vóór eerste target | 60 leerlingen | 1 | 160 / 2 | `null` / `missingTarget` |

Twee onderwijsselecties en twee prijssnapshots bij één boeking leveren in de
regressie nog steeds één boeking/exportregel op. Kalenderbezetting heeft een
aanvullende browserfixture: 60 leerlingen op een dag met override 100 gebruikt
60/100, niet de standaardnoemer 160. Dagdetails tonen capaciteit 100 en restant 40.

Aanvullende sectorregressie: dezelfde definitieve PO-boeking van 60 leerlingen
op 8 maart, populatie **Alleen definitief**, programma **Dag**. Onderstaande
uitkomsten gelden zowel vrij als na handmatige blokkade, plannervakantie en
gegenereerde vakantie:

| Sectorfilter | Geselecteerde boekingen / leerlingen | Capaciteitsdagen | Capaciteit leerlingen / plekken | Targetdagen | Target leerlingen / boekingen |
| --- | --- | --- | --- | --- | --- |
| Alle sectoren | 1 / 60 | 1 | 160 / 2 | 1 | 100 / 1 |
| PO | 1 / 60 | 1 | 160 / 2 | 1 | 100 / 1 |
| VO onderbouw | 0 / 0 | 1 | 160 / 2 | 1 | 100 / 1 |

De regressie vergelijkt volledige capaciteits- en targetresultaten met de
vrije dag. Extra controles behouden de status- en programmascope: een optie
kwalificeert voor planning maar niet voor alleen-definitief, afgewezen
records kwalificeren nooit, en een dagboeking activeert geen gesloten
ochtenddag. Geen aanvullende query of join nodig.

## Browserfout en oplossing in de fixture

De fout `Cannot read properties of null (reading 'ce')` bij `renderSlot` kwam
uit de testopstelling. In de vastgelegde mislukking stonden twee verschillende
Vue-renderingruntimes in dezelfde pagina: `chunk-GPNS7JJO.js` en
`chunk-KDDCHVNP.js`. De stack voerde `renderComponentRoot` uit in de eerste,
maar `renderSlot` in de tweede. Die tweede runtime had geen huidige
renderende component. De fout trad op bij `AdminCollapsibleHelp` nadat het
echte, lui geladen agendabeheer werd geopend.

De virtuele fixture-entry werd niet door Vite's gewone HTML-entryscan gevonden.
Latere dependency discovery bouwde de geoptimaliseerde dependencies opnieuw,
waardoor oude en nieuwe runtimechunks samen geladen werden. De componentimports
gebruikten gewoon `vue`; er is geen slot- of componentpatch aangebracht om dit
te maskeren. De fixture prebundelt nu `vue`, `vue-router` en `lucide-vue-next`
samen met `optimizeDeps.include`.

De definitieve test controleert expliciet één Vue-runtime, geen browserfouten
of console-errors en uitsluitend GET-requests. Enter/Tab/Escape blijven echte
CDP-toetsaanslagen; muisbediening gebruikt echte muisevents. Geen vervanging
door `element.click()`. De assertion voor de geblokkeerde beheeractie selecteert
de daadwerkelijke actieknop; de slotknop **Selectie wissen** is een andere knop.

De fixture monteert de echte `DashboardCalendarView`, inclusief `KeepAlive`,
lazy beheer, `AdminDialog`, dagdetails, RouterLinks en admin-CSS. HTTP-antwoorden
zijn synthetisch. Het boekingsdetail na de RouterLink is een minimale
terugkeerfixture; daarin wordt geen echte boekingsmutatie uitgevoerd.

Bewijsbestanden buiten Git, onder `%TEMP%`:

- `geofort-blocked-planning-browser-9HTPGX/failure.json`: twee runtimes en de oorspronkelijke renderstack.
- `geofort-blocked-planning-browser-qH4hwx/failure.json`: aparte reproductie van de verouderde maandcache; na terugkeer nog 210 leerlingen / 5 bezoekdagen, zonder nieuwe overzichtsaanvraag.
- `geofort-blocked-planning-browser-PMkvZ4/checks.json`: definitieve geslaagde controle; screenshots `calendar-1440.png`, `calendar-768.png`, `calendar-375.png`, `day-detail-375.png` in dezelfde map.

## Uitgevoerde verificatie

PHP 8.3.28; muterende integratietests uitsluitend in nieuw aangemaakte,
willekeurig benoemde disposable databases op lokale MariaDB 11.4.9,
`127.0.0.1:3307`. Geen verbinding met `geoform_db` voor deze tests. Fixtures
worden na afloop verwijderd. Geen applicatie-bootstrap/.env in de nieuwe
integratietest, geen echte mail, import of seeder.

- `BlockedDayPlanningMariaDbIntegrationTest.php`: **128 controles geslaagd**.
  Werkelijke SQL/services, analytics, targets, export, omzet en kalenderdetails.
  Werkelijke kalendercontrollers en sessiebewaking in afzonderlijke PHP-processen:
  zonder geldige sessie geen kalenderdata (303), POST op leesroutes 405,
  geldige GET 200 met de bestaande planning.
  De sectorregressie faalde vóór de correctie bij de technische capaciteit
  van `voortgezetOnderbouw` na handmatig blokkeren; daarna slaagt de matrix
  voor technische capaciteit, officiële targets en dagsnapshots.
- `CalendarDateReleaseMariaDbIntegrationTest.php`: **127 controles geslaagd**
  eerder in deze run, resultaat hergebruikt omdat de geteste mutatiecode
  ongewijzigd is. Inclusief autorisatie, actiegebonden CSRF,
  vrijgave-uitzonderingen, audit en rollback bij database-/auditfouten.
- `BookingRevenueReportMariaDbIntegrationTest.php`: geslaagd eerder in deze
  run; ongewijzigde omzetimplementatie.
- Domein- en contracttests voor BookingAnalytics (basis, deep, weekdays,
  advanced, HTTP, frontend), CapacityTarget (domein, HTTP, frontend),
  DashboardCalendar (domein, HTTP, frontend), DashboardCalendarOverview
  (domein, HTTP, frontend) en CalendarDateManagement-frontend: geslaagd.
  Geraakte capaciteits-/overzichtstests opnieuw uitgevoerd na de laatste
  backendaanpassing; overige geslaagde resultaten hergebruikt.
- `BookingStatusDomainFoundationTest.php`, `BookingStatusUpdateHttpContractTest.php`,
  `BookingVisitDateDomainTest.php`, `BookingVisitDateUpdateHttpContractTest.php`:
  geslaagd. De datumvalidator blijft `DISABLED_VISIT_DATE` melden voor
  bevestigen/wijzigen op een gesloten datum.
- `capacityTargetScenarioInteractionTest.mjs` en
  `dashboardCalendarOverviewInteractionTest.mjs`: geslaagd.
- `blockedDayPlanningBrowserTest.mjs`: geslaagd op 1440, 768 en 375 pixels.
  Blokkade en aantallen zichtbaar, leesbare woorden, interne horizontale
  kalenderscroll, toetsenbord/muis naar details, Escape en focusterugkeer,
  beheermutatie blijft uitgeschakeld. Terugkeer na een veranderd leesantwoord
  geeft 150 leerlingen / 4 bezoekdagen; de lege dag blijft geblokkeerd.
- `npx.cmd vue-tsc -b` en `npm.cmd run build`: geslaagd. De build meldt de
  bestaande onopgeloste verwijzing `./assets/images/form-heightlines.png`;
  de build slaagt en dit asset valt buiten deze wijziging.
- PHP 8.3-syntaxcontrole van alle 13 gewijzigde/nieuwe PHP-bestanden: geslaagd.
- `git diff --check`: geslaagd.

Na de sectorcorrectie zijn uitsluitend de geraakte controles herhaald:
de disposable MariaDB-suite, BookingAnalyticsDomain, BookingAdvancedAnalyticsDomain,
CapacityTargetDomain, beide bijbehorende frontendcontracten, PHP 8.3-syntax
van de vijf aangepaste PHP-bestanden, TypeScript/build en diffcontrole.
De eerdere kalenderbrowser-, autorisatie- en vrijgaveresultaten zijn hergebruikt;
die code is bij deze aanvullende correctie niet gewijzigd.

De browserfixture bewijst presentatie en bediening, geen serverautorisatie.
Daarvoor zijn de afzonderlijke echte controller-/sessietests gebruikt. Een
volledige ingelogde browsercontrole via WAMP/PHP met de geïmporteerde database
is niet uitgevoerd. Er zijn geen tests tegen productie uitgevoerd.

## Bestaande grenzen

Planning bekijken verleent geen toestemming om te bevestigen, wijzigen of
verplaatsen. De bestaande validatie en expliciete overrideflow blijven
gelden, ook wanneer een datum voor rapportages meetelt. Die beheerhandeling
kan dus nog een geblokkeerde-datumwaarschuwing of andere bestaande
validatiefout geven. Geen automatische vrijgave, datumuitzondering,
dataherstel of migratie toegevoegd.

`geoform_db`, lokale mailbeveiliging en MySQL-eventinstellingen zijn niet
gewijzigd. De afzonderlijke bedoelde verwijdering van
`public/assets/booking/roosters/images/po_skd_6.png` blijft buiten deze feature.
