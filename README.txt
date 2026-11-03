GeoFort configuratie — getoetst releasepakket

Applicatiecommit: 114ef633d041467bb34331ddc9b2bfda56018eaf (main)
Deze aparte branch bevat alleen het uitrolpakket en de checksum.
Geen databasekopie, lokale instellingen, credentials of vendorbestanden.

Na ophalen: sha256sum -c SHA256SUMS
Pak het archief uit naar /tmp. Voer deploy.sh prepare uit vóór activate.
Prepare controleert de huidige live-basis ba9e9d7 en maakt back-ups.
Alleen twee nieuwe configuratietabellen worden toegevoegd; bestaande aanvragen en roosters blijven behouden.
Automatische evaluatieverzending blijft uit. Terugval: deploy.sh rollback.
Zie README.txt binnen het archief voor de volledige controle- en terugvalvoorwaarden.
