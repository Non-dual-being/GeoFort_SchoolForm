-- GeoFort roosterplanner - centrale personeelsconfiguratie.
-- Salarissen/kostprijzen zijn bewust historisch modelleerbaar, maar nog niet ingevuld.

CREATE TABLE IF NOT EXISTS roster_staff_members (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  display_name VARCHAR(120) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  notes VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roster_staff_members_name (display_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roster_staff_module_preferences (
  staff_id BIGINT UNSIGNED NOT NULL,
  module_key VARCHAR(120) NOT NULL,
  preference_rank TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (staff_id, module_key),
  KEY idx_roster_staff_module_rank (module_key, preference_rank, staff_id),
  CONSTRAINT fk_roster_staff_module_preferences_staff
    FOREIGN KEY (staff_id) REFERENCES roster_staff_members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roster_staff_cost_rates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  staff_id BIGINT UNSIGNED NOT NULL,
  valid_from DATE NOT NULL,
  valid_to DATE NULL,
  hourly_cost_cents INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_roster_staff_cost_rates_staff_period (staff_id, valid_from, valid_to),
  CONSTRAINT fk_roster_staff_cost_rates_staff
    FOREIGN KEY (staff_id) REFERENCES roster_staff_members(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO roster_staff_members (id, display_name, is_active) VALUES
  (1, 'Frank Duijnhouwer', 1),
  (2, 'Frank van Kessel', 1),
  (3, 'Hester Smit', 1),
  (4, 'Jantien Poodt', 1),
  (5, 'Kevin Hink', 1),
  (6, 'Loek Hendriks', 1),
  (7, 'Maarten Willems', 1),
  (8, 'Marc Lagewaard', 1),
  (9, 'Mayke van den Boom', 1),
  (10, 'Nathalja Beens', 1),
  (11, 'Noud Willemse', 1),
  (12, 'Rik Overtoom', 1),
  (13, 'Wiert Ruben', 1),
  (14, 'Wil Verhoeven', 1),
  (15, 'Piet Visser', 1),
  (16, 'Ries Euser', 1),
  (17, 'Wouter van Berkel', 1),
  (18, 'Kevin de Schepper', 1)
ON DUPLICATE KEY UPDATE display_name = VALUES(display_name);

INSERT INTO roster_staff_module_preferences (staff_id, module_key, preference_rank) VALUES
  (1, 'Dynamische-Globe', 1),
  (1, 'Klimaat-Experience', 2),
  (1, 'Earth-Watch', 3),

  (2, 'Dynamische-Globe', 1),
  (2, 'Klimaat-Experience', 2),
  (2, 'Earth-Watch', 3),
  (2, 'Voedsel-Innovatie', 4),
  (2, 'Crisismanagement', 5),

  (3, 'Stop-de-Klimaat-Klok', 1),
  (3, 'Klimaat-Experience', 2),
  (3, 'Earth-Watch', 3),
  (3, 'Voedsel-Innovatie', 4),

  (4, 'Klimaat-Experience', 1),

  (5, 'Minecraft-Klimaatspeurtocht', 1),
  (5, 'Minecraft-Programmeren', 1),
  (5, 'Klimaat-Experience', 2),
  (5, 'Stop-de-Klimaat-Klok', 3),

  (6, 'Dynamische-Globe', 1),
  (6, 'Stop-de-Klimaat-Klok', 2),
  (6, 'Earth-Watch', 3),
  (6, 'Klimaat-Experience', 4),
  (6, 'Voedsel-Innovatie', 5),

  (7, 'Minecraft-Klimaatspeurtocht', 1),
  (7, 'Minecraft-Programmeren', 1),
  (7, 'Stop-de-Klimaat-Klok', 2),

  (8, 'Stop-de-Klimaat-Klok', 1),
  (8, 'Voedsel-Innovatie', 2),
  (8, 'Earth-Watch', 3),
  (8, 'Dynamische-Globe', 4),
  (8, 'Klimaat-Experience', 5),
  (8, 'Minecraft-Klimaatspeurtocht', 6),
  (8, 'Minecraft-Programmeren', 6),
  (8, 'Crisismanagement', 7),

  (9, 'Voedsel-Innovatie', 1),
  (9, 'Stop-de-Klimaat-Klok', 2),
  (9, 'Earth-Watch', 3),
  (9, 'Klimaat-Experience', 4),

  (10, 'Voedsel-Innovatie', 1),
  (10, 'Stop-de-Klimaat-Klok', 2),
  (10, 'Earth-Watch', 3),
  (10, 'Klimaat-Experience', 4),
  (10, 'Dynamische-Globe', 5),

  (11, 'Voedsel-Innovatie', 1),
  (11, 'Earth-Watch', 2),
  (11, 'Stop-de-Klimaat-Klok', 3),
  (11, 'Klimaat-Experience', 4),
  (11, 'Dynamische-Globe', 5),

  (12, 'Voedsel-Innovatie', 1),
  (12, 'Stop-de-Klimaat-Klok', 2),
  (12, 'Earth-Watch', 3),
  (12, 'Minecraft-Klimaatspeurtocht', 4),
  (12, 'Minecraft-Programmeren', 4),
  (12, 'Klimaat-Experience', 5),
  (12, 'Dynamische-Globe', 6),

  (13, 'Dynamische-Globe', 1),
  (13, 'Klimaat-Experience', 2),

  (14, 'Voedsel-Innovatie', 1),
  (14, 'Stop-de-Klimaat-Klok', 2),
  (14, 'Dynamische-Globe', 3),
  (14, 'Klimaat-Experience', 4),

  (15, 'Klimaat-Experience', 1),
  (15, 'Dynamische-Globe', 2),
  (15, 'Earth-Watch', 3),

  (16, 'Dynamische-Globe', 1),
  (16, 'Klimaat-Experience', 2),
  (16, 'Earth-Watch', 3),

  (17, 'Dynamische-Globe', 1),
  (17, 'Voedsel-Innovatie', 2),
  (17, 'Stop-de-Klimaat-Klok', 3),
  (17, 'Minecraft-Klimaatspeurtocht', 4),
  (17, 'Minecraft-Programmeren', 4),
  (17, 'Earth-Watch', 5),
  (17, 'Crisismanagement', 6),
  (17, 'Klimaat-Experience', 7),

  (18, 'Minecraft-Klimaatspeurtocht', 1),
  (18, 'Minecraft-Programmeren', 1),
  (18, 'Stop-de-Klimaat-Klok', 2),
  (18, 'Earth-Watch', 3),
  (18, 'Crisismanagement', 4),
  (18, 'Klimaat-Experience', 5),
  (18, 'Voedsel-Innovatie', 6)
ON DUPLICATE KEY UPDATE preference_rank = VALUES(preference_rank);