# Productiedomein-cutover

Dit document is een handout voor de latere omschakeling. Het voert nu niets uit. Vul alle `<...>`-waarden in op basis van een read-only audit op de productieserver en laat een tweede beheerder de waarden controleren.

## Huidige URL-architectuur

`BASE_URL` uit de environment is de enige actieve canonieke origin voor absolute links en redirects. `EnvironmentBaseUrlProvider` valideert deze tegen de allowlist. Productie staat zowel het acceptatieadres `https://onderwijsboeking.test.ignorelist.com` als `https://onderwijsboeking.geofort.nl` toe; één deployment activeert maar één daarvan. Inkomende `Host`, `Origin` en `Referer` bepalen nooit de base-URL. Frontend-API's, assets, login en logout gebruiken root-relative paden.

## Read-only Nginx- en certificaatinventaris

Voer vóór het wijzigingsvenster op de server uit en archiveer de uitvoer bij het change-ticket:

```sh
sudo nginx -T
sudo nginx -t
sudo certbot certificates
sudo openssl x509 -in <certificate-fullchain-or-cert-path> -noout -subject -issuer -dates -ext subjectAltName
systemctl status nginx
systemctl status <php-fpm-unit>
```

Leg expliciet vast:

| Onderdeel | Huidige waarde | Gewenste waarde / controle |
| --- | --- | --- |
| `server_name` | `<uit nginx -T>` | Bevat exact `onderwijsboeking.geofort.nl` |
| Root/proxy target | `<oude app-root of upstream>` | Nieuwe release `public`/`current/public`, nooit repositoryroot |
| Oude applicatie | `<releasepad + versie/tag>` | Ongewijzigd en direct herstelbaar |
| SSL-certificaat | `<certificaatpad + serienummer>` | SAN bevat domein en verloopt niet rond venster |
| PHP-FPM | `<socket/upstream + versie>` | PHP 8.3-pool, correcte rechten/time-outs |
| Fallback | `<try_files>` | Bestaande PHP-entrypoints en statische assets werken |
| Headers | `<include/add_header>` | CSP/frame/HSTS/referrer/nosniff behouden |
| Uploadlimiet | `<client_max_body_size>` | Bestaande waarde behouden tenzij apart goedgekeurd |
| Logs | `<access_log>`, `<error_log>` | Schrijfbaar en tijdens cutover gevolgd |

Er staat geen Nginx-configuratie in deze repository en deze lokale audit heeft geen toegang tot de live vhost. Daarom mogen concrete huidige waarden niet worden geraden.

## Voorgestelde Nginx-diff

Maak pas na bovenstaande inventaris een diff tegen het werkelijk actieve bestand. De functionele wijziging hoort beperkt te blijven tot het applicatietarget:

```diff
 server {
     server_name onderwijsboeking.geofort.nl;
-    root <huidige-oude-app-root>;
+    root <nieuwe-release-of-current>/public;

     # Behoud listen/SSL-certificaat, PHP-FPM fastcgi_params,
     # try_files, securityheaders, uploadlimieten en logpaden.
 }
```

Gebruik bij een proxy-opstelling dezelfde minimale targetwijziging voor `proxy_pass` in plaats van `root`. Voeg geen redirect van acceptatie naar productie toe.

## Certificaatreadiness

- Controleer dat de SAN exact `onderwijsboeking.geofort.nl` bevat, `notAfter` ruim na het venster ligt en Nginx naar hetzelfde certificaat/fullchain en key-paar verwijst.
- Controleer `certbot renew --dry-run` in een apart, goedgekeurd beheerwindow en verifieer de renewal timer. Wijzig Certbot niet tijdens deze voorbereiding.
- Omdat het domein al op dezelfde server live is, is geen DNS-wijziging voorzien. Stop als de vhostaudit een ander endpoint of certificaattraject toont.

## Vooraf

1. Rond planneracceptatie af en registreer release/commit.
2. Merge de actuele goedgekeurde `main` via het normale reviewproces.
3. Tag de intacte eerste productielijn en noteer tag en releasepad.
4. Maak en verifieer een databaseback-up; documenteer herstelcommando, locatie, checksum en verantwoordelijke.
5. Maak een back-up/snapshot van oude applicatie en actieve Nginx-config.
6. Rond bovenstaande vhost- en certificaatcontrole af.
7. Deploy de nieuwe release zonder de actieve productievhost om te zetten.
8. Voer healthcheck en volledige kritieke flow uit op het acceptatieadres.
9. Leg communicatie, eigenaar, starttijd, go/no-go en rollbackbeslisser vast.

## Write stop

1. Sluit het oude formulier zichtbaar voor nieuwe inzendingen; laat alleen een onderhoudsmelding zien.
2. Verifieer server-side dat nieuwe writes naar uitsluitend de oude database niet meer mogelijk zijn.
3. Noteer laatste oude aanvraag-ID en tijdstip.
4. Draai de legacyimport eerst met `--dry-run` volgens `docs/legacy-booking-migration.md`.
5. Voer alleen indien nodig en expliciet goedgekeurd de laatste append-only import uit.
6. Draai opnieuw dry-run en bevestig nul onverwachte toevoegingen, updates of duplicaten.
7. Stop bij onverklaarde verschillen; houd het oude formulier gesloten of rol de write stop gecontroleerd terug.

## Omschakeling

1. Zet in de gedeelde environment van uitsluitend de nieuwe deployment `APP_ENV=production` en `BASE_URL=https://onderwijsboeking.geofort.nl`; herstel geen secrets uit documentatie.
2. Vernieuw alleen toepasselijke config/runtimecache volgens het deploymentrunbook; wis geen brede directories.
3. Pas uitsluitend de gecontroleerde vhost-root/upstream aan naar de nieuwe release.
4. Draai `sudo nginx -t`. Bij een fout: niets reloaden en configback-up terugplaatsen.
5. Voer `sudo systemctl reload nginx` uit; restart alleen bij aantoonbare noodzaak.
6. Bevestig met responseheaders en logs dat requests bij de nieuwe release uitkomen.

## Productiesmoketest

Stel uitsluitend op de echte productiedeployment `MAIL_BOOKING_REQUEST_BCC_EMAILS=onderwijs@geofort.nl` in, naast `APP_ENV=production`. Laat deze instelling leeg op local/dev/test/acceptatie/staging; acceptatie gebruikt hier immers ook `APP_ENV=production`. De instelling voegt BCC toe aan de bestaande nieuwe-aanvraagmail, met behoud van ontvanger en `MAIL_CC_EMAILS`. Statusmails uit het dashboard gebruiken deze BCC niet. Zonder deze instelling blijft de BCC leeg.

- HTTPS-homepage en formulier laden zonder mixed content of assetfouten.
- Formulierconfig-, kalender- en validatie-API's antwoorden correct.
- Dashboardlogin, sessie, agenda, export en analytics werken.
- Maak één herkenbare testaanvraag, controleer opslag, capaciteit en exact één e-mail.
- Controleer dat e-maillinks het productiedomein gebruiken.
- Controleer login/logout- en interne redirectlocaties; geen redirect naar acceptatie of willekeurige host.
- Controleer access/error/PHP/mail-logs tijdens alle stappen.
- Controleer certificaatketen, HSTS/securityheaders en mobiel formulier.
- Verwijder/testmarkeer de testaanvraag alleen via het afgesproken administratieve proces.

## Rollback

Rollback is een expliciete operationele beslissing; bepaal eerst wat met writes sinds de omschakeling gebeurt.

1. Sluit nieuwe writes tijdelijk en noteer alle nieuwe aanvraag-ID's sinds het cutovertijdstip.
2. Classificeer deze aanvragen voor handmatige verwerking/import. Herstel nooit blind een oude database over nieuwe productiegegevens.
3. Zet de Nginx-root/upstream terug naar het vastgelegde oude releasepad.
4. Herstel de oude environment alleen als die tijdens cutover is gewijzigd; bewaar de nieuwe environment voor een volgende poging buiten het actieve target.
5. Open de oude applicatie pas nadat databasekeuze en writepad expliciet zijn bevestigd.
6. Draai `sudo nginx -t` en daarna `sudo systemctl reload nginx`.
7. Verifieer oude homepage/formulier, één gecontroleerde write, dashboard indien aanwezig, e-mail, HTTPS en logs.
8. Informeer planners over handmatig te behandelen nieuwe test-/productieaanvragen en leg oorzaak, tijden en bewijs vast.

## Go/no-go en bewijs

Bewaar: acceptatierapport, Git-tag/commit, releasepaden, backupchecksums, geanonimiseerde importresultaten, vhostdiff, `nginx -t`, certificaatcontrole, smoketestresultaten, logtijdstippen en eventuele rollbackbeslissing. Sla geen secrets of volledige persoonsgegevens in het change-ticket op.
