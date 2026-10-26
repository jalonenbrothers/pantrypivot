# PantryPivot — migrations

Plain SQL, numbered in run order. MySQL (Hostinger hPanel / phpMyAdmin).

## How to run (operator)

1. Open **hPanel → Databases → phpMyAdmin** for the subdomain's database.
2. Click the **SQL** tab (or Import tab), paste the contents of each file
   below **in order**, run.
3. Every file is **safe to re-run** (`CREATE TABLE IF NOT EXISTS` only).
4. Then run the recipe import script once (see `scripts/import-recipes.php`
   in the repo README) — it fills `recipes`, `recipe_ingredients`,
   `ingredients`, `synonyms`, `staple_weights` from TheMealDB + Open Food
   Facts.

## Files

| # | File | What it creates | Re-run safe |
|---|------|-----------------|-------------|
| 1 | `001_pantrypivot.sql` | `tokens`, `ingredients`, `synonyms`, `pantry_items`, `recipes`, `recipe_ingredients`, `staple_weights` | yes |

No data migrations exist yet; app code is written to tolerate empty tables
(empty-state UX handles a bare database).

CLI alternative (if you have shell access):
```
mysql -u USER -p DBNAME < migrations/001_pantrypivot.sql
```