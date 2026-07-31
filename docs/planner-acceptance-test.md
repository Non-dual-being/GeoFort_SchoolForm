# Planneracceptatietest

Test uitsluitend op het actuele acceptatieadres. Noteer per regel het werkelijke resultaat, kies `Geslaagd` of `Mislukt` en voeg bij afwijkingen een aanvraagnummer, tijdstip en schermafbeelding toe. Controleer vooraf dat e-mails niet naar echte klanten gaan.

## Formulier

| Nr. | Doel | Invoer | Stappen | Verwacht resultaat | Werkelijk resultaat | Geslaagd/mislukt | Opmerkingen |
| --- | --- | --- | --- | --- | --- | --- | --- |
| F01 | PO dagprogramma | Geldige PO-school, dagprogramma en datum | Vul alle stappen in en verstuur | Aanvraag wordt eenmaal opgeslagen en bevestiging verschijnt |  |  |  |
| F02 | PO ochtendprogramma | Geldige PO-school en ochtendprogramma | Doorloop en verstuur | Alleen geldige ochtendopties en correcte aanvraag |  |  |  |
| F03 | VO onderbouw | VO onderbouw, geldig niveau en groepen | Doorloop en verstuur | Programma en rooster passen bij onderbouw |  |  |  |
| F04 | VO bovenbouw | VO bovenbouw, geldig niveau en groepen | Doorloop en verstuur | Programma en rooster passen bij bovenbouw |  |  |  |
| F05 | Niveaus en groepen | Elke aangeboden combinatie | Selecteer combinaties achtereenvolgens | Alleen backend-geconfigureerde opties zijn beschikbaar |  |  |  |
| F06 | Minimumaantal | Aantal precies op minimum | Verstuur aanvraag | Minimum wordt geaccepteerd |  |  |  |
| F07 | Maximumaantal | Aantal precies op maximum | Verstuur aanvraag | Maximum wordt geaccepteerd |  |  |  |
| F08 | Buiten aantallimiet | Eén onder minimum en één boven maximum | Probeer beide aanvragen | Duidelijke validatiefout; niets opgeslagen |  |  |  |
| F09 | Keuzemodule | Geldige module per programma | Selecteer module en verstuur | Module staat correct in aanvraag en dashboard |  |  |  |
| F10 | Catering | Elke relevante cateringkeuze | Selecteer en verstuur | Keuze en prijsweergave zijn correct |  |  |  |
| F11 | CJP | Met en zonder CJP-gegevens | Verstuur beide varianten | Alleen vereiste CJP-velden worden afgedwongen |  |  |  |
| F12 | Opmerkingen | Meerdere regels, maximaal 600 tekens | Verstuur en open in dashboard | Regelafbrekingen blijven behouden en uitvoer is veilig |  |  |  |
| F13 | Voorwaarden | Niet aangevinkt, daarna aangevinkt | Probeer te versturen | Eerst fout; daarna succesvolle aanvraag |  |  |  |
| F14 | Algemene validatie | Lege/verkeerd gevormde verplichte velden | Verstuur | Veldgerichte fouten; geen aanvraag |  |  |  |
| F15 | Reeds bezette datum | Datum op capaciteit | Selecteer en verstuur | Datum geblokkeerd of backend weigert de aanvraag |  |  |  |
| F16 | Uitgeschakelde datum | Disabled date | Selecteer datum | Datum is niet boekbaar en reden klopt waar getoond |  |  |  |
| F17 | Mobiel formulier | Gangbare telefoonbreedte | Doorloop volledig | Geen onbereikbare velden; versturen werkt |  |  |  |

## Dashboard

| Nr. | Doel | Invoer | Stappen | Verwacht resultaat | Werkelijk resultaat | Geslaagd/mislukt | Opmerkingen |
| --- | --- | --- | --- | --- | --- | --- | --- |
| D01 | Aanvraag openen | Bekend aanvraagnummer | Zoek en open | Alle opgeslagen gegevens worden juist getoond |  |  |  |
| D02 | Aanvraag aanpassen | Geldige contact- en groepswijziging | Bewerk en sla op | Wijziging opgeslagen zonder onbedoelde statuswijziging |  |  |  |
| D03 | Status In optie | Nieuwe aanvraag | Zet status op In optie | Status, historie en toepasselijke mail kloppen |  |  |  |
| D04 | Status Definitief | Geldige optie | Zet Definitief | Validatie slaagt, status/historie/mail kloppen |  |  |  |
| D05 | Afwijzen | Aanvraag met reden | Kies Afwijzen | Status, historie en afwijzingsmail kloppen |  |  |  |
| D06 | Override | Bewust afwijkende geldige testcase en reden | Gebruik override | Reden vereist en audittrail bevat regel en gebruiker |  |  |  |
| D07 | Bezoekdatum wijzigen | Vrije geldige datum | Wijzig en sla op | Capaciteit opnieuw gevalideerd; historie klopt |  |  |  |
| D08 | Programma wijzigen | Compatibel programma | Wijzig en sla op | Afhankelijke configuratie en validatie kloppen |  |  |  |
| D09 | Catering wijzigen | Andere cateringkeuze | Wijzig en sla op | Catering en berekening worden juist bijgewerkt |  |  |  |
| D10 | Historie | Aanvraag uit D02-D09 | Open historie | Alle mutaties chronologisch en herleidbaar |  |  |  |
| D11 | Agenda | Testdatums | Open maand en dagdetails | Bezetting, capaciteit en statussen kloppen |  |  |  |
| D12 | Export | Datum-/statusfilter | Bekijk samenvatting en download CSV | Filter, aantallen, encoding en inhoud kloppen |  |  |  |
| D13 | Analytics | Periode met bekende data | Open analytics en wijzig filters | Totalen sluiten aan op brondata/export |  |  |  |
| D14 | Desktopdashboard | Gangbare desktopbreedte | Doorloop hoofdschermen | Navigatie, tabellen en dialogen blijven bruikbaar |  |  |  |

## E-mail

| Nr. | Doel | Invoer | Stappen | Verwacht resultaat | Werkelijk resultaat | Geslaagd/mislukt | Opmerkingen |
| --- | --- | --- | --- | --- | --- | --- | --- |
| E01 | Aanvraagbevestiging | Nieuwe acceptatieaanvraag | Verstuur en inspecteer mail | Eén juiste bevestiging met juiste boekingsdata |  |  |  |
| E02 | Statusmail | Statuswijziging | Voer wijziging uit en inspecteer mail | Juiste template, ontvanger en status |  |  |  |
| E03 | Gewijzigde boeking | Boeking uit dashboard | Wijzig relevante gegevens | Alleen bedoelde mail wordt verzonden en inhoud klopt |  |  |  |
| E04 | Afzender/reply-to | Elke testmail | Inspecteer headers | Geconfigureerde afzender en reply-to zijn juist |  |  |  |
| E05 | Acceptatielinks | Elke testmail | Open alle applicatielinks | Links wijzen naar de actieve acceptatieomgeving |  |  |  |
| E06 | Geen vroege productielink | Elke testmail vóór cutover | Zoek domeinen in HTML en tekst | Geen link wijst vóór cutover naar `onderwijsboeking.geofort.nl` |  |  |  |

## Regressie

| Nr. | Doel | Invoer | Stappen | Verwacht resultaat | Werkelijk resultaat | Geslaagd/mislukt | Opmerkingen |
| --- | --- | --- | --- | --- | --- | --- | --- |
| R01 | Historische boekingen | Steekproef oud/nieuw seizoen | Zoek en open | Gegevens blijven volledig en leesbaar |  |  |  |
| R02 | Imported legacybookings | Bekende legacy-ID's | Vergelijk met bron/dry-runrapport | Herkomst en waarden kloppen; geen duplicaten |  |  |  |
| R03 | Kalendercapaciteit | Dagen rond limieten | Vergelijk dashboard en formulier | Zelfde backendcapaciteit wordt toegepast |  |  |  |
| R04 | Disabled dates | Handmatig en geïmporteerd geblokkeerde data | Controleer kalender/formulier | Alle blokkades blijven effectief |  |  |  |
| R05 | Geen dubbele boeking | Zelfde aanvraag twee keer snel versturen | Dubbelklik/netwerkherhaling simuleren | Geen onbedoelde dubbele boeking |  |  |  |
| R06 | Geen dubbele e-mail | Eén aanvraag/statusactie | Inspecteer mailbox/log | Exact één toepasselijke mail |  |  |  |

## Afronding

Acceptatie is pas compleet als alle P0/P1-afwijkingen zijn opgelost, P2-afwijkingen een expliciete beslissing hebben en de planner naam, datum, geteste release/commit en eindoordeel heeft vastgelegd.
