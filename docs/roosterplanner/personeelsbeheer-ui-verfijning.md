# Personeelsbeheer: UI-verfijning en unieke rangen

Deze aanvulling op Ronde C behoudt de bestaande catalogus, append-only audit, roosterimpactcontrole en Ronde D-reset. Er is geen schema- of seedwijziging nodig.

## Beheerpagina

`/roosters/personeel` heeft een afzonderlijke beheerzone met Medewerker toevoegen en direct zoeken op weergavenaam. Zoeken werkt lokaal, hoofdletterongevoelig, ook voor inactieve medewerkers, met resultaatteller, wisknop en lege toestand. Een wijziging herlaadt alleen de catalogus; toevoegen maakt de nieuwe kaart zichtbaar en geeft de wijzigknop focus. Een zoekterm die het opgeslagen resultaat zou verbergen wordt dan gewist.

Cards tonen maximaal vijf modulevaardigheden in voorkeurvolgorde. Bij zes of meer wordt alleen het modulegebied scrollbaar en bereikbaar met het toetsenbord. De natuurlijke hoogte van de langste modulenaam bepaalt gelijke rijhoogtes, zodat vijf volledige regels passen zonder afgeknipte tekst. ResizeObserver past dit aan bij breedte- en lettertypewijzigingen. Kaartkop, badges en de onderste wijzigknop blijven buiten het scrollgebied. Cards en onderste acties lijnen per gridrij uit.

De editor gebruikt bestaande dashboardcontrols, focusstijlen, kleuren en radius. Modulekaarten staan op desktop in twee kolommen; op tablet en mobiel in één. Op mobiel staat de rang onder de modulekeuze, met voldoende binnenruimte. Geselecteerde kaarten hebben een subtiel sterker blauw accent.

## Overgang naar unieke voorkeursrangen

- Nieuwe medewerkers en inhoudelijk gewijzigde voorkeuren vereisen unieke gehele rangen 1–255 per medewerker.
- Nieuwe modules krijgen `max(alle behouden geselecteerde rangen) + 1`, inclusief onbekende legacyvaardigheden. Gaten worden niet dichtgeschoven. Bij 255 wordt toevoegen geblokkeerd totdat de gebruiker zelf ruimte maakt.
- De backend vergelijkt de volledige canonieke moduleKey/rank-verzameling vóór en na, onder de bestaande cataloguslock. Hij vertrouwt geen frontend-vlag voor deze beslissing.
- Bij een ongewijzigde verzameling blijven bestaande dubbele rangen exact behouden, ook bij naam, actiefstatus, dienstverband, rollen, deactiveren en opnieuw activeren. Alleen gewijzigde voorkeursrijen worden door de bestaande repository geschreven.
- Zodra de voorkeuren inhoudelijk veranderen, moeten alle behouden rangen uniek zijn, inclusief onbekende modules. Duplicaten geven HTTP 422 `DUPLICATE_STAFF_PREFERENCE_RANK`; ongeldige getallen worden eveneens server-side geweigerd. De UI geeft gekoppelde inline fouten en blokkeert opslaan.
- Onbekende modulekeys blijven automatisch behouden. `legacyPreferenceRanks` accepteert uitsluitend expliciete rangwijzigingen aan eigen, bestaande, behouden legacyvaardigheden. `removeLegacyModuleKeys` blijft de expliciete verwijderactie; verwijderen van een gebruikte vaardigheid doorloopt nog steeds de impactcontrole.

Er wordt niets genormaliseerd bij laden, migreren, deployen of een gewone persoonsgegevenswijziging. De lokale nulmeting had 20 medewerkers, 93 vaardigheden en 7 medewerkers met dubbele rangen: IDs 3, 5, 7, 8, 12, 17 en 19. Die operationele gegevens zijn tijdens deze ronde niet gewijzigd.

## Deactiveren, historie en concurrency

Een afzonderlijke actie Medewerker deactiveren staat onderaan de editor, gescheiden van het formulier en de bewaaracties. Openstaande wijzigingen moeten eerst worden opgeslagen of geannuleerd. Na de bestaande server-side impactpreview volgt een dialoog met conceptrooster-ID, datum en reden. Annuleren krijgt standaard focus; Escape sluit en herstelt de focus. Tijdens de mutatie zijn sluiten en acties geblokkeerd.

`POST /api/admin/rosters/staff/deactivate.php` gebruikt private dashboardauth en eigen CSRF-scope `deactivate-roster-staff`. De body bevat uitsluitend `staffId`, `expectedVersion`, `expectedCatalogRevision` en `impactToken`. De service haalt alle overige waarden onder de cataloguslock uit de database, valideert opnieuw de versies en ondertekende impact, en verwerkt alleen `isActive = false` via de bestaande transactionele save/auditflow. Een conflict geeft HTTP 409. Er is geen DELETE-endpoint of GET-mutatie.

Inactieve medewerkers verdwijnen uit nieuwe planning. Bestaande concepttoewijzingen blijven zichtbaar en blokkeren finalisatie totdat ze zijn opgelost. Opnieuw activeren kan met Actief in de editor. Definitieve snapshots en school-PDF's blijven immutable.

Hard-delete blijft ook voor een nooit ingeroosterde medewerker verboden: `roster_staff_no_delete` en de RESTRICT-koppeling met de immutable personeelsaudit worden niet versoepeld. Toevoegen maakt direct een auditrecord. Historische roosterbetrokkenheid wordt dus nooit via een delete-cascade verloren.

## Regressies

Gerichte domein-, HTTP-, MariaDB/MySQL- en concurrencytests dekken toevoegen, rollen, legacybehoud, rangcorrectie, expliciete legacyverwijdering, eigen CSRF, replay/conflicten en deactiveren tegenover finalisatie/nieuwe selectie. De browsertest draait de echte Vue-componenten met synthetische HTTP-data; geen applicatiedatabasemutaties of mailtransport. Zij controleert 1600, 1280, 768, 390 en 375 px, exact vijf zichtbare modules, uitgelijnde knoppen, zoekverzoeken, inline fouten, max+1, max255, focus, Escape en de bevestiging tijdens een uitgestelde request.

De overige V1/A/B/C/D-tests blijven onderdeel van de eindcontrole, inclusief seed/bootstrapveiligheid, cook-ID, snapshots/PDF-bytes, XLSX, generator, manual staffing, lifecycle en statusmailsemantiek. Uitvoer, nulmeting, broncheckpoint, lokale backup en bestandsmanifest staan in `local-backups/planner-v1-rondeC-personeel-ui/20261006-1433-96d60f77/`.
