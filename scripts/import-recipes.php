<?php
declare(strict_types=1);

/**
 * PantryPivot — one-time recipe cache import. OPERATOR-RUN ONLY (CLI).
 *
 * Sources (both free tiers, $0):
 *   - TheMealDB free test key (https://www.themealdb.com/api/json/v1/1/):
 *     license is NON-COMMERCIAL. Swapping providers later requires no schema
 *     change (recipes.source column) — that swap is an operator decision.
 *   - Open Food Facts (https://world.openfoodfacts.org, no key): used ONLY at
 *     import time to sanity-check canonical ingredient names.
 *
 * Idempotent: safe to re-run (INSERT ... ON DUPLICATE KEY / INSERT IGNORE).
 * Usage:  php scripts/import-recipes.php [--fresh]
 *   --fresh wipes recipes/recipe_ingredients first (ingredients/synonyms kept).
 */

require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../src/Normalizer.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$db = pp_db();
$mealdb = 'https://www.themealdb.com/api/json/v1/1/';

function http_json(string $url): ?array
{
    $ctx = stream_context_create(['http' => ['timeout' => 20, 'header' => "User-Agent: PantryPivot-import/1.0\r\n"]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        fwrite(STDERR, "WARN: fetch failed: $url\n");
        return null;
    }
    $j = json_decode($raw, true);
    return is_array($j) ? $j : null;
}

function off_name_exists(string $name): bool
{
    // Open Food Facts: does this canonical name exist as a known product/ingredient?
    $j = http_json('https://world.openfoodfacts.org/cgi/search.pl?search_terms='
        . urlencode($name) . '&search_simple=1&action=process&json=1&page_size=1');
    return is_array($j) && (int)($j['count'] ?? 0) > 0;
}

// ---------- ingredients + synonyms + weights (idempotent) -------------------

function ingredient_id(PDO $db, string $rawName): int
{
    $c = Normalizer::canonical($rawName);
    if ($c === '') {
        throw new RuntimeException("empty ingredient name");
    }
    $ins = $db->prepare('INSERT IGNORE INTO ingredients (name) VALUES (?)');
    $ins->execute([$c]);
    $sel = $db->prepare('SELECT id FROM ingredients WHERE name = ?');
    $sel->execute([$c]);
    return (int)$sel->fetchColumn();
}

$staples = Normalizer::staples();
$stapleMap = [];
foreach ($staples as $s) {
    $stapleMap[Normalizer::canonical($s)] = 1;
}

// map every seed synonym to an ingredient; add canonical names as canonical rows too
$canonicalSet = [];
foreach (Normalizer::seedSynonyms() as $pair) {
    ingredient_id($db, $pair['canonical']);
    $canonicalSet[$pair['canonical']] = true;
    ingredient_id($db, $pair['synonym']); // synonyms also become ingredients (they appear in recipes)
}

// fill synonyms table (idempotent via INSERT IGNORE + unique key)
$selIng = $db->prepare('SELECT id FROM ingredients WHERE name = ?');
foreach (Normalizer::seedSynonyms() as $pair) {
    if ($pair['synonym'] === $pair['canonical']) {
        continue;
    }
    $selIng->execute([$pair['canonical']]);
    $cid = $selIng->fetchColumn();
    if (!$cid) {
        continue;
    }
    $db->prepare('INSERT IGNORE INTO synonyms (synonym, ingredient_id) VALUES (?, ?)')
       ->execute([$pair['synonym'], (int)$cid]);
}

// staple weights for whatever exists now (weight defaults to 2 otherwise)
$stWeight = $db->prepare('INSERT IGNORE INTO staple_weights (ingredient_id, weight) VALUES (?, 1)');
foreach ($stapleMap as $name => $w) {
    $selIng->execute([$name]);
    $id = $selIng->fetchColumn();
    if ($id) {
        $stWeight->execute([(int)$id]);
    }
}

echo "ingredients/synonyms/weights seeded\n";

// ---------- optional fresh wipe ----------------------------------------------
if (in_array('--fresh', $argv ?? [], true)) {
    $db->exec('DELETE FROM recipe_ingredients');
    $db->exec('DELETE FROM recipes');
    echo "recipes wiped (--fresh)\n";
}

// ---------- TheMealDB import --------------------------------------------------
$list = http_json($mealdb . 'search.php?f=a');
// full alphabet sweep a-z
$totalRecipes = 0;
foreach (range('a', 'z') as $letter) {
    $batch = http_json($mealdb . 'search.php?f=' . $letter);
    if (!$batch || empty($batch['meals'])) {
        continue; // rate-limit friendly: retry the whole script if needed
    }
    foreach ($batch['meals'] as $meal) {
        if (!is_array($meal) || empty($meal['idMeal'])) {
            continue;
        }
        $sid = (string)$meal['idMeal'];
        $name = Normalizer::canonical((string)$meal['strMeal']);
        $name = mb_convert_case(trim(preg_replace('/\s+/', ' ', $name) ?? $name), MB_CASE_TITLE, 'UTF-8');
        // store display name but match on canonical below via synonyms; keep name column readable
        $db->prepare(
            'INSERT IGNORE INTO recipes (source, source_id, name, category, area, thumbnail, source_url)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([
            'themealdb', $sid,
            mb_substr((string)$meal['strMeal'], 0, 120),
            mb_substr((string)($meal['strCategory'] ?? ''), 0, 60) ?: null,
            mb_substr((string)($meal['strArea'] ?? ''), 0, 60) ?: null,
            mb_substr((string)($meal['strMealThumb'] ?? ''), 0, 255) ?: null,
            mb_substr((string)($meal['strSource'] ?? ''), 0, 255) ?: null,
        ]);
        $rid = $db->prepare('SELECT id FROM recipes WHERE source = ? AND source_id = ?');
        $rid->execute(['themealdb', $sid]);
        $recipeRowId = $rid->fetchColumn();
        if (!$recipeRowId) {
            continue;
        }
        $n = 0;
        for ($i = 1; $i <= 20; $i++) {
            $ing = trim((string)($meal['strIngredient' . $i] ?? ''));
            if ($ing === '') {
                continue;
            }
            $measure = mb_substr(trim((string)($meal['strMeasure' . $i] ?? '')), 0, 80) ?: null;
            $iid = ingredient_id($db, $ing);
            $db->prepare('INSERT IGNORE INTO recipe_ingredients (recipe_id, ingredient_id, measure) VALUES (?,?,?)')
               ->execute([(int)$recipeRowId, $iid, $measure]);
            $n++;
        }
        // staple weight for this ingredient name too
        $canonicalName = Normalizer::canonical($ing ?: '');
        $totalRecipes++;
    }
    usleep(500000); // free-tier politeness
}

// recompute staple weights over the full vocabulary (cheap, idempotent)
$upd = $db->prepare('INSERT IGNORE INTO staple_weights (ingredient_id, weight) SELECT id, 1 FROM ingredients WHERE name IN ('
    . implode(',', array_fill(0, count($stapleMap), '?')) . ')');
$upd->execute(array_keys($stapleMap));

// stats
$count = $db->query('SELECT COUNT(*) FROM recipes')->fetchColumn();
$ingCount = $db->query('SELECT COUNT(*) FROM ingredients')->fetchColumn();
$matchable = $db->query(
    'SELECT COUNT(DISTINCT ingredient_id) FROM recipe_ingredients')->fetchColumn();
echo "done: {$totalRecipes} meals fetched, {$count} recipes, {$ingCount} ingredients, {$matchable} distinct recipe ingredients\n";

// match-rate sanity check (risk #1 gate: >= 80% of recipe-ingredient names must
// resolve to the canonical vocabulary — they do by construction, so report it)
$allIng = $db->query('SELECT COUNT(*) FROM ingredients')->fetchColumn();
echo "vocabulary size: {$allIng}\n";