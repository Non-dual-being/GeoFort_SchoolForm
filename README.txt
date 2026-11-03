GeoFort dashboarduitlijning
Applicatiecommit: d1eb581123583882d5abb8f2d449e9da46738188
Verwachte live-basis: 114ef633d041467bb34331ddc9b2bfda56018eaf
Alleen vier dashboard-CSS-bestanden en gebouwde frontendbestanden.
Geen PHP-applicatiecode, afhankelijkheden, lokale instellingen of databasegegevens meegeleverd.
Geen migraties, databasewijzigingen, mails of mailtaakwijzigingen.
Prepare maakt een nieuwe release en controleert de huidige bronbestanden en gedeelde configuratie.
Aanvragen, roosters, programmaconfiguratie en evaluatiegegevens worden alleen uitgelezen en vergeleken.
Bestaande periodes en evaluatie-instellingen blijven behouden, ook als die ingeschakeld staan.
Activate controleert de bereikbaarheid, gebouwde bestanden en bestaande roosters/rapporten.
Bij mislukte controle na omschakeling wordt de oude code automatisch teruggezet.
Back-ups en status: /home/deploy/backups/geofort-header-d1eb581
Gebruik prepare voor activate. Bij een fout stoppen en de uitvoer delen, niet forceren.
Geen serverherstart; alleen de bestaande PHP-FPM-service wordt herladen.
Na activering kan rollback de vorige code herstellen zonder gegevens terug te zetten.
sudo bash /tmp/geofort-header-d1eb581/deploy.sh prepare|activate|status|rollback
