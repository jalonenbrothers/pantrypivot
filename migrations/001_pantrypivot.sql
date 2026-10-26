-- PantryPivot migration 001 — full schema.
-- Source-agnostic recipe cache: swapping TheMealDB for another provider later
-- requires NO schema change (source column exists for exactly that).
-- MySQL syntax (Hostinger target). Safe to re-run: uses IF NOT EXISTS everywhere.

CREATE TABLE IF NOT EXISTS tokens (
    token      VARCHAR(64)  NOT NULL PRIMARY KEY,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Canonical ingredient vocabulary (autocomplete + matching key).
CREATE TABLE IF NOT EXISTS ingredients (
    id   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80)  NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- OFF (Open Food Facts) / curated import-time synonym map: synonym -> canonical.
CREATE TABLE IF NOT EXISTS synonyms (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    synonym       VARCHAR(80)  NOT NULL UNIQUE,
    ingredient_id INT UNSIGNED NOT NULL,
    KEY idx_syn_ing (ingredient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One anonymous pantry per token cookie (no accounts — see scope lock).
CREATE TABLE IF NOT EXISTS pantry_items (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    token         VARCHAR(64)  NOT NULL,
    ingredient_id INT UNSIGNED NOT NULL,
    qty           VARCHAR(80)  NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pantry (token, ingredient_id),
    KEY idx_pantry_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recipes (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    source     VARCHAR(20)  NOT NULL,               -- e.g. 'themealdb'; swap providers freely
    source_id  VARCHAR(40)  NOT NULL,
    name        VARCHAR(120) NOT NULL,
    category   VARCHAR(60)  NULL,
    area       VARCHAR(60)  NULL,
    thumbnail   VARCHAR(255) NULL,
    source_url  VARCHAR(255) NULL,
    UNIQUE KEY uq_recipe_source (source, source_id),
    KEY idx_recipe_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Recipe -> ingredient join with the measure string from the source.
CREATE TABLE IF NOT EXISTS recipe_ingredients (
    recipe_id     INT UNSIGNED NOT NULL,
    ingredient_id INT UNSIGNED NOT NULL,
    measure       VARCHAR(80)  NULL,
    PRIMARY KEY (recipe_id, ingredient_id),
    KEY idx_ri_ing (ingredient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Staple weighting for missing-item ranking (tunable; see README).
-- weight 1 = pantry staple (oil/salt/pepper...), 2 = everything else.
CREATE TABLE IF NOT EXISTS staple_weights (
    ingredient_id INT UNSIGNED     NOT NULL PRIMARY KEY,
    weight        TINYINT UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;