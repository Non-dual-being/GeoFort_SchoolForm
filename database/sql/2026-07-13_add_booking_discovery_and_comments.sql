-- Eenmalig handmatig uitvoeren op de onderwijsboeking_v2-database.
-- De kolommen nemen charset en collatie van de bestaande tabel over.
ALTER TABLE aanvragen
  ADD COLUMN hoe_kent_u_geofort VARCHAR(120) NULL AFTER bezoekdatum,
  ADD COLUMN opmerkingen TEXT NULL AFTER hoe_kent_u_geofort;
