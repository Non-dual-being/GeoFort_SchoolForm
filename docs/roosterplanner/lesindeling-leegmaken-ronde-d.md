# V1 Ronde D — Lesindeling leegmaken

De actie op de rooster-detailpagina verwijdert de lessen van een conceptrooster. Het rooster zelf, de booking-koppeling en de opgeslagen basis voor opnieuw plannen blijven bestaan. Definitieve roosters moeten eerst via de bestaande lifecycle worden heropend.

## API en transactie

`POST /api/admin/rosters/reset-sessions.php` vereist een private dashboardsessie, JSON en de eigen CSRF-scope `reset-roster-sessions`. De dashboardbootstrap levert daarvoor `rosterResetSessionsCsrfToken`. De body bevat uitsluitend positieve gehele getallen `planId` en `expectedRevision`.

`RosterSessionResetService` neemt binnen één transactie eerst de Ronde-C-cataloguslock en daarna de planlock. Hij neemt daarna geen dag- of bookinglock. De catalogusrevisie verandert niet. Status en revisie worden onder de lock opnieuw gecontroleerd. Een ontbrekend rooster geeft 404; een ongeschikte status of achterhaalde revisie geeft 409 zonder wijziging. De frontend toont het conflict, herlaadt en verstuurt de reset niet automatisch opnieuw.

Bij een echte reset voert de repository een voorbereide `DELETE` uit met zowel `roster_plan_id` als `item_type = 'activity'`. Het verwijderde aantal wordt gecontroleerd. Daarna verhoogt de bestaande `advanceRevision()` de revision exact eenmaal en schrijft de uitvoerende admin; `updated_at` wordt door de bestaande tabeldefinitie bijgewerkt. Een fout draait de hele transactie terug, inclusief cascades.

Als er geen activity-sessies zijn, wordt de locktransactie zonder DML beëindigd. De response is succesvol met `deletedSessionCount: 0`, `alreadyEmpty: true` en dezelfde revision. Ook `updated_at`, `updated_by_admin_id` en alle overige databasewaarden blijven gelijk. Een stale request blijft ook op een leeg concept een 409.

Succesresponse:

```json
{"ok":true,"data":{"planId":12,"revision":8,"deletedSessionCount":4,"alreadyEmpty":false}}
```

## Gegevensgrens

| Verwijderen | Behouden |
| --- | --- |
| `roster_sessions` met `item_type = activity`, inclusief module, tijden en sessielocatie | `roster_plans`, booking/programmacontext en bestaande bookinggegevens |
| `roster_session_groups`, inclusief `supervision_kind` School/OPEN | `roster_groups` |
| `roster_session_staff`, inclusief `assignment_source` | `roster_manual_plan_context`, modulekeuzes, daggrenzen en de volledige `rounds_json` |
| `roster_session_group_staff`, inclusief handmatige/optimizer-toewijzingen | `roster_plan_staff`, beschikbaarheid, staffing settings, kokkeuze en dagvoorkeuren |
| Alleen bovengenoemde sessieafhankelijkheden | Ronde-C-medewerkers, skills, audit, catalogusrevisie en bootstrapregistratie |
| | Immutable snapshots, lifecyclehistorie, school-PDF-records en bytes |

De bestaande FK-cascades verwijderen de drie koppeltabellen vanuit de sessies; er loopt geen cascade naar groepen of medewerkers. Er zijn in het huidige model geen afzonderlijke optimizerresultaat- of sessielocatietabellen. Neutrale dagblokken staan in `rounds_json`, niet in `roster_sessions`.

Een combinatie van activity-sessies en een onbekend sessietype wordt defensief geweigerd met `UNSUPPORTED_SESSION_TYPE` (409). Als geen activity-sessie bestaat, geldt de no-op ook bij een onbekend type: alles blijft behouden.

Er is geen schemawijziging en geen nieuw lifecycle-event. De bestaande eventsoorten beschrijven finaliseren/heropenen. Een reset-event met de huidige restrictieve historiekoppeling zou bovendien het verwijderen van een nog nooit gefinaliseerd concept blokkeren. De reset gebruikt dezelfde revision/actor/tijdregistratie als andere sessiebewerkingen.

## Interface

“Lesindeling leegmaken” staat bij de roosteracties, alleen bij Concept. Bij Definitief wordt uitgelegd dat eerst heropenen nodig is. De modal noemt expliciet wat verdwijnt en wat behouden blijft. Annuleren krijgt focus; Escape annuleert vóór het verzoek. Tijdens de request zijn de dialoogknoppen uitgeschakeld. Fouten en conflicts blijven zichtbaar, succesmeldingen verdwijnen automatisch.

Na succes worden open sessie-editors en generatorvoorstellen losgelaten en wordt het rooster herladen. De handmatige tabel gebruikt voortaan de opgeslagen `planning.rounds`, inclusief neutrale dagblokken. Ook na het toevoegen van de eerste les blijven de andere lege rondes beschikbaar. Uitzonderlijke bestaande sessietijden blijven zichtbaar naast de opgeslagen rondes.

## Verificatie

- `RosterSessionResetMariaDbIntegrationTest.php`: echte cascades, alle behouden databasewaarden, exacte no-op zonder schrijf-SQL, één revisionstap, geforceerde rollback, opnieuw handmatig/generator, weigering Definitief, heropenen/reset/herfinaliseren en byte-identieke oude snapshot/PDF.
- `DashboardRosterSessionResetHttpContractTest.php`: private auth, afzonderlijke CSRF, POST-only, strikte JSON/body, responsevorm, 404/409/500 en no-op zonder databasewrite.
- `RosterSessionResetConcurrencyMariaDbIntegrationTest.php`: onafhankelijke verbindingen voor reset/reset, twee lege no-ops, reset/finalisatie, sessiebewerking, generator en Ronde-C-impactbevestiging.
- `dashboardRosterSessionResetBrowserTest.mjs`: echte Vue/CSS met synthetische HTTP-responses; bevestiging, annuleren, focus, loading, fouten, 409 zonder herhaling, no-op, eigen rondetijden na reset én na eerste handmatige opslag, Definitief/heropenen; desktop en mobiel.

Destructieve integratietests gebruiken uitsluitend lokale disposable databases met expliciete opt-in en afgeschermde databasenamen. De browsertest schrijft geen applicatiedata en verstuurt geen mail. Het private runrapport bevat nulmeting, backuphash, exacte testresultaten, bronwijzigingen en de read-only databasevergelijking.
