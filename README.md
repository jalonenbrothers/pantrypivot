# PantryPivot

What's actually in your kitchen decides what you cook tonight. PantryPivot
matches your pantry against a locally cached recipe set and gives plain
verdicts: **Cook tonight** (nothing missing), **You're close** (1-2 items
away, with "add flour → 4 more meals open up" unlocks), **Later** (3+).

LAMP house stack: PHP 8+ (no frameworks), MySQL via PDO (sqlite dev
fallback), vanilla HTML5/JS/CSS, no build steps. No accounts — anonymous
token cookie + copy/paste pantry key.

## Deploy (Hostinger subdomain — operator runbook)

Target: `myapp4mvp.earth-turtle.com` (next free slot in the lab's
subdomain series; operator may substitute). Repo root = web root.

1. **hPanel → Subdomains**: create `myapp4mvp` under `earth-turtle.com`.
   Note the document root it prints, e.g.
   `/home/uXXXXXXX/domains/earth-turtle.com/subdomains/myapp4mvp`.
2. **hPanel → Databases → MySQL**: create database + user (names like
   `u123456789_pantrypivot` / `u123456789_pantrypivot`). Note the
   password and the MySQL hostname shown (usually `localhost`).
3. Upload/git-clone this repo INTO the subdomain's document root so that
   `index.php` and `.htaccess` sit at its top level (git push auto-deploy
   counts; repo root must equal web root).
4. `cp lib/config.example.php lib/config.php` — fill in db host/port,
   database name, user, password from hPanel. Leave `debug => false`.
5. Run migrations/ per `migrations/README.md` (phpMyAdmin SQL tab,
   paste `migrations/001_pantrypivot.sql`, or CLI import).
6. Import the recipe cache ONCE (fills `recipes`,
   `recipe_ingredients`, `ingredients`, `synonyms`, `staple_weights`
   from TheMealDB + Open Food Facts). hPanel → Advanced → SSH/terminal,
   from the web root:
   ```
   php scripts/import-recipes.php
   ```
   It is idempotent (safe to re-run) and CLI-only (not web-exposed);
   takes ~1-2 min and caches ~790 recipes. It must run on Hostinger
   (against the Hostinger DB), so it needs hPanel SSH/terminal access.
   If your plan has no shell access, flag it in the sprint channel —
   the fallback is a temporary hPanel cron job that runs the same
   command once, then delete the cron job.
7. Verify: browse `https://myapp4mvp.earth-turtle.com/` — pantry page
   loads; add a few items; Verdicts page shows recipes; check
   `.../lib/config.php` returns 404 (it must).
8. Done — no build steps, no cron jobs, no other moving parts.

### Hostinger notes

- PHP version: 8.1+ required (8.3 verified in the lab's Apache
  container; 8.5 verified via CLI). hPanel defaults are fine.
- `.htaccess` is already in the repo root (pretty URLs + blocked
  internal dirs); Hostinger Apache reads it as-is — verified pattern,
  same as lab-blog.
- No request-time API calls; the only external calls happen inside the
  one-time import script.
- Costs: **$0**. Both APIs are free tiers. TheMealDB free test-key data
  is NON-COMMERCIAL — fine for a lab MVP; commercial use would need a
  recipe-source swap (schema is source-agnostic, `recipes.source`).
- SSL: hPanel auto-issues Let's Encrypt for subdomains; nothing to do.

## Local dev (tested)

Fastest (sqlite, no docker):

```
cp lib/config.example.php lib/config.php
#   edit db to: 'name' => 'sqlite:pantrypivot.sqlite', host/user/pass ''
php scripts/init-dev-db.php        # creates lib/pantrypivot.sqlite with the schema
php -S 127.0.0.1:8080 router.php   # browse http://127.0.0.1:8080/
```

- `php scripts/init-dev-db.php --test` creates the test db
  (`lib/pantrypivot-test.sqlite`) — used by `tests/run.php`.
- The dev db starts EMPTY (no recipes). The recipe import script
  speaks MySQL SQL (`INSERT IGNORE`) and will NOT run against sqlite —
  to browse with real data locally, use the docker stack below.
- `php tests/run.php` — 41/41 pass (18 unit + 23 e2e).

Full-stack local (Apache + MySQL, mirrors Hostinger) — docker:

```
docker network create pp-net   # once
docker run -d --name pp-mysql --network pp-net \
  -e MYSQL_DATABASE=pantrypivot -e MYSQL_USER=pp -e MYSQL_PASSWORD=pp \
  -e MYSQL_ROOT_PASSWORD=pproot -p 23307:3306 mysql:8.4
docker run -d --name pp-web --network pp-net -p 18090:80 \
  -v $(pwd):/var/www/html php:8.3-apache

# enable mod_rewrite + AllowOverride (one-time, inside the container;
# Hostinger already has both on):
docker exec pp-web sh -c "a2enmod rewrite && printf '<Directory /var/www/html>\n\tOptions FollowSymLinks\n\tAllowOverride All\n\tRequire all granted\n</Directory>\n' > /etc/apache2/conf-enabled/pp.conf && apachectl graceful"

# schema + data:
docker exec -i pp-mysql mysql -upp -ppp pantrypivot < migrations/001_pantrypivot.sql
cp lib/config.example.php lib/config.php
#   edit config.php: host => 'pp-mysql', name => 'pantrypivot',
#   user => 'pp', pass => 'pp'
docker exec pp-web php scripts/import-recipes.php
# browse http://127.0.0.1:18090/
```

Verified with exactly this stack (mysql:8.4 + php:8.3-apache):
pretty URLs work, /lib/config.php and friends 404, import pulls 790
recipes, verdicts/suggest APIs return real data.

## API surface (JSON, same origin)

| Method | Path             | What |
|--------|------------------|------|
| GET    | `/api/suggest?q=` | autocomplete suggestions (vocabulary + synonyms) |
| GET    | `/api/pantry`    | your pantry items + token |
| GET    | `/api/verdicts`  | full verdict payload (cook_now/near_miss/later/unlock) |

Mutations are form POSTs (progressive enhancement — works with JS off):
`/add`, `/remove`, `/example`, `/import`.

## External APIs (import-time only, $0)

- **TheMealDB** free test key, cached once into MySQL. License is
  NON-COMMERCIAL — swapping recipe sources later is an operator decision;
  the schema is source-agnostic (`recipes.source` column).
- **Open Food Facts** — no key, name-normalization help at import only.
- No API calls at request time. Zero paid tiers.

## Tests

```
php tests/run.php
```
Unit tests for the match engine + normalizer, then an HTTP e2e pass over
the dev router (routes, forms, CSRF, blocked paths, API endpoints).
Last run: **41/41 pass** (18 unit + 23 e2e), PHP 8.5 CLI.

## Security

Prepared statements everywhere; output escaped via `pp_e()`; CSRF token on
all POSTs; `.htaccess` blocks `/lib /src /tests /migrations /scripts /data`
and `config.example.php`; `config.php` gitignored; debug=false never leaks
stack traces; missing config shows a plain "Setup needed" page. Import
script is CLI-only (not web-exposed).
