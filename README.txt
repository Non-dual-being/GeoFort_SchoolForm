GeoFort configuratie — getoetst releasepakket r3

Applicatiecommit: 114ef633d041467bb34331ddc9b2bfda56018eaf (main)
Deze aparte branch bevat alleen het uitrolpakket en de checksum.
Geen databasekopie, lokale instellingen, credentials of vendorbestanden.

r3 behoudt de bestaande evaluatie-instellingen, ook als de dashboardinstelling aan staat.
De uitrol verstuurt geen mail, installeert geen mailtaak en start geen automatische verzending.
De controle op afwezige visit-surveys-services/timers blijft bestaan.
Beleidscontroles hebben geen SQLite nodig. De applicatiecode en frontend zijn gelijk aan r2.

r3 gebruikt het bestaande herstelpunt /home/deploy/backups/geofort-configuratie-114ef63-r2.
De r2-kandidaat, live-basis en gedeelde configuratie worden gecontroleerd voor hervatten.
Het migratiejournaal en alle back-ups blijven behouden; tabellen worden niet verwijderd of geleegd.

Na ophalen: sha256sum -c SHA256SUMS
Pak het archief uit naar /tmp. Voer deploy.sh prepare uit vóór activate.
Prepare controleert de huidige live-basis ba9e9d7 en maakt nieuwe back-ups.
Bestaande aanvragen, roosters en evaluatiegegevens worden voor en na vergeleken.
Terugval: deploy.sh rollback. Zie README.txt in het archief voor voorwaarden.
