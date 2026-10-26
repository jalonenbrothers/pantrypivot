-- PantryPivot TEST/dev schema — SQLite translation of migrations/001_pantrypivot.sql.
-- Production is MySQL; this exists so tests/run.php and local dev (config
-- db.name = 'sqlite:...') work without a MySQL server. Keep column names and
-- semantics in sync with the MySQL migration.
CREATE TABLE IF NOT EXISTS tokens (
    token      TEXT NOT NULL PRIMARY KEY,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS ingredients (
    id   INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    name TEXT    NOT NULL UNIQUE
);
CREATE TABLE IF NOT EXISTS synonyms (
    id            INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    synonym       TEXT    NOT NULL UNIQUE,
    ingredient_id INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_syn_ing ON synonyms (ingredient_id);
CREATE TABLE IF NOT EXISTS pantry_items (
    id            INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    token         TEXT    NOT NULL,
    ingredient_id INTEGER NOT NULL,
    qty           TEXT,
    created_at    TEXT    NOT NULL DEFAULT (datetime('now')),
    UNIQUE (token, ingredient_id)
);
CREATE INDEX IF NOT EXISTS idx_pantry_token ON pantry_items (token);
CREATE TABLE IF NOT EXISTS recipes (
    id         INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    source     TEXT NOT NULL,
    source_id  TEXT NOT NULL,
    name       TEXT NOT NULL,
    category   TEXT,
    area       TEXT,
    thumbnail  TEXT,
    source_url TEXT,
    UNIQUE (source, source_id)
);
CREATE INDEX IF NOT EXISTS idx_recipe_name ON recipes (name);
CREATE TABLE IF NOT EXISTS recipe_ingredients (
    recipe_id     INTEGER NOT NULL,
    ingredient_id INTEGER NOT NULL,
    measure       TEXT,
    PRIMARY KEY (recipe_id, ingredient_id)
);
CREATE INDEX IF NOT EXISTS idx_ri_ing ON recipe_ingredients (ingredient_id);
CREATE TABLE IF NOT EXISTS staple_weights (
    ingredient_id INTEGER NOT NULL PRIMARY KEY,
    weight        INTEGER NOT NULL DEFAULT 1
);