<?php
declare(strict_types=1);

/**
 * PantryPivot — test runner (house style: tests/run.php, sqlite dev db, no
 * frameworks). Two layers:
 *   1. Unit: MatchEngine + Normalizer pure-function tests (no DB).
 *   2. E2E: spin the app up via dev router + sqlite db and hit the real
 *      HTTP surface with a cookie jar (the dev router mirrors .htaccess).
 *
 * Run:  php tests/run.php
 * Exits non-zero on any failure. Prints "X passed, Y failed".
 */

require __DIR__ . '/../src/Normalizer.php';
require __DIR__ . '/../src/MatchEngine.php';

$fail = 0;
$pass = 0;

function check(bool $cond, string $label): void
{
    global $fail, $pass;
    if ($cond) {
        $pass++;
        echo "ok    $label\n";
    } else {
        $fail++;
        echo "FAIL  $label\n";
    }
}

// ---------- Normalizer ----------
check(Normalizer::canonical('  Plain Flour, self-raising ') === 'plain flour self raising', 'canonical collapses + strips punctuation');
check(Normalizer::canonical('Spring Onions') === 'spring onions', 'canonical lowercases');
check(Normalizer::resolve('Spring Onions', ['spring onions' => 'scallion']) === 'scallion', 'resolve maps synonym');
check(Normalizer::resolve('nope', ['x' => 'y']) === null, 'resolve miss = null');
$syn = Normalizer::seedSynonyms();
check(count($syn) > 50, 'seed synonyms count ' . count($syn));
check(in_array('salt', Normalizer::staples(), true), 'salt is a staple');

// ---------- MatchEngine: classification ----------
$pantry = [
    ['ingredient_id' => 1, 'name' => 'flour'],
    ['ingredient_id' => 2, 'name' => 'butter'],
];
$recipe = [
    ['ingredient_id' => 1, 'name' => 'flour'],
    ['ingredient_id' => 2, 'name' => 'butter'],
];
$s = MatchEngine::score($pantry, $recipe, []);
check($s['class'] === MatchEngine::CLASS_COOK_NOW, '0 missing = COOK NOW');
check($s['have'] === 2 && $s['missing'] === 0, 'have/missing counts');

$recipe[] = ['ingredient_id' => 3, 'name' => 'egg'];
$s = MatchEngine::score($pantry, $recipe, []);
check($s['class'] === MatchEngine::CLASS_NEAR_MISS, '1 missing = NEAR MISS');
$recipe[] = ['ingredient_id' => 4, 'name' => 'milk'];
$s = MatchEngine::score($pantry, $recipe, []);
check($s['class'] === MatchEngine::CLASS_NEAR_MISS, '2 missing = still NEAR MISS');
$recipe[] = ['ingredient_id' => 5, 'name' => 'sugar'];
$s = MatchEngine::score($pantry, $recipe, []);
check($s['class'] === MatchEngine::CLASS_LATER, '3 missing = LATER');

// weights in score
$s = MatchEngine::score([], [['ingredient_id' => 9, 'name' => 'chicken']], [9 => 2]);
check($s['missing_items'][0]['weight'] === 2, 'missing item carries weight');

// ---------- MatchEngine: verdict line ----------
$score = ['have' => 7, 'missing' => 0, 'class' => 'cook_now', 'missing_items' => []];
check(MatchEngine::verdictLine($score, 9) === 'You have 7/9 — nothing to buy.', 'cook-now verdict line');
$score = ['have' => 7, 'missing' => 2, 'class' => 'near_miss',
          'missing_items' => [['name' => 'flour', 'weight' => 1], ['name' => 'butter', 'weight' => 1]]];
check(MatchEngine::verdictLine($score, 9) === 'You have 7/9 — missing flour, butter.', 'near-miss verdict line');

// ---------- MatchEngine: unlock ranking ----------
$nm = [
    ['recipe' => ['id' => 1], 'score' => ['missing_items' => [['name' => 'flour', 'weight' => 1]]]],
    ['recipe' => ['id' => 2], 'score' => ['missing_items' => [['name' => 'flour', 'weight' => 1], ['name' => 'butter', 'weight' => 1]]]],
    ['recipe' => ['id' => 3], 'score' => ['missing_items' => [['name' => 'flour', 'weight' => 1]]]],
    ['recipe' => ['id' => 4], 'score' => ['missing_items' => [['name' => 'butter', 'weight' => 1]]]],
];
$rank = MatchEngine::unlockRanking($nm);
check(($rank['flour'] ?? 0) === 3, 'flour unlocks 3');
check(($rank['butter'] ?? 0) === 2, 'butter unlocks 2');
check(array_key_first($rank) === 'flour', 'unlock ranking sorted desc');

// ---------- MatchEngine: missing sort (staples sink) ----------
$sorted = MatchEngine::sortMissing([
    ['name' => 'salt', 'weight' => 1],
    ['name' => 'chicken breast', 'weight' => 2],
]);
check($sorted[0]['name'] === 'chicken breast', 'heavier missing item sorts first');

// ---------- E2E (dev router + sqlite) -----------------------------------------
$root = dirname(__DIR__);
$dbFile = $root . '/lib/pantrypivot-test.sqlite';
@unlink($dbFile);

// minimal dev config
file_put_contents($root . '/lib/config.php',
    "<?php declare(strict_types=1);\nreturn ['db'=>['host'=>'','port'=>3306,'name'=>'sqlite:pantrypivot-test.sqlite','user'=>'','pass'=>''],'debug'=>true];\n");

// apply test schema (dedicated sqlite translation of the MySQL migration;
// production still runs migrations/*.sql via the operator per migrations/README)
$sql = file_get_contents(__DIR__ . '/schema.sqlite.sql');
$pdo = new PDO('sqlite:' . $dbFile, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec($sql);

// seed vocabulary + one recipe via the same code path as import script
require $root . '/src/Pantry.php';
require_once $root . '/lib/db.php';
require_once $root . '/src/Normalizer.php';

function ing_id(PDO $pdo, string $raw): int
{
    $c = Normalizer::canonical($raw);
    $pdo->prepare('INSERT OR IGNORE INTO ingredients (name) VALUES (?)')->execute([$c]);
    $st = $pdo->prepare('SELECT id FROM ingredients WHERE name = ?');
    $st->execute([$c]);
    return (int)$st->fetchColumn();
}
foreach (['flour', 'butter', 'egg', 'milk', 'sugar', 'salt', 'black pepper', 'cooking oil', 'spring onions', 'scallion'] as $n) {
    ing_id($pdo, $n);
}
foreach (Normalizer::seedSynonyms() as $pair) {
    ing_id($pdo, $pair['canonical']);
    $cid = ing_id($pdo, $pair['canonical']);
    $pdo->prepare('INSERT OR IGNORE INTO synonyms (synonym, ingredient_id) VALUES (?,?)')
       ->execute([$pair['synonym'], $cid]);
}
foreach (Normalizer::staples() as $st) {
    $id = ing_id($pdo, Normalizer::canonical($st));
    $pdo->prepare('INSERT OR IGNORE INTO staple_weights (ingredient_id, weight) VALUES (?,1)')->execute([$id]);
}
// pancakes: flour butter egg milk — all present in vocab
$pdo->exec("INSERT INTO recipes (source, source_id, name, category, area, thumbnail, source_url) VALUES ('themealdb','t1','Pancakes','Dessert','British',NULL,NULL)");
$pancakesId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO recipes (source, source_id, name) VALUES ('themealdb','t2','Omelette')");
$omeletteId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO recipes (source, source_id, name) VALUES ('themealdb','t3','Boeuf Bourguignon')");
$stewId = (int)$pdo->lastInsertId();
foreach ([['flour','1 cup'], ['butter','2 tbsp'], ['egg','2'], ['milk','1 cup']] as [$n, $m]) {
    $pdo->prepare('INSERT OR IGNORE INTO recipe_ingredients (recipe_id, ingredient_id, measure) VALUES (?,?,?)')
       ->execute([$pancakesId, ing_id($pdo, $n), $m]);
}
foreach ([['egg','3'], ['butter','1 tbsp'], ['salt','']] as [$n, $m]) {
    $pdo->prepare('INSERT OR IGNORE INTO recipe_ingredients (recipe_id, ingredient_id, measure) VALUES (?,?,?)')
       ->execute([$omeletteId, ing_id($pdo, $n), $m !== '' ? $m : null]);
}
foreach ([['egg','1'], ['butter','2 tbsp'], ['milk','1'], ['flour','2 tbsp'], ['salt',''], ['black pepper','']] as [$n, $m]) {
    $pdo->prepare('INSERT OR IGNORE INTO recipe_ingredients (recipe_id, ingredient_id, measure) VALUES (?,?,?)')
       ->execute([$stewId, ing_id($pdo, $n), $m !== '' ? $m : null]);
}

// ---- start dev server + curl the surface ----
$port = 18023;
$cmd = 'exec ' . escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' ' . escapeshellarg($root . '/router.php');
$proc = proc_open($cmd, [1 => ['file', '/tmp/pp-dev.log', 'w'], 2 => ['file', '/tmp/pp-dev.log', 'w']], $pipes, $root);
usleep(400000);

function req(string $method, string $url, array $data = [], ?string $cookieJar = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    if ($data) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }
    if ($cookieJar) {
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
    }
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $hdrEnd = strpos((string)$raw, "\r\n\r\n");
    return [$code, $hdrEnd ? substr((string)$raw, $hdrEnd + 4) : (string)$raw];
}

$base = "http://127.0.0.1:$port";
$jar = '/tmp/pp-cookies.txt';
@unlink($jar);

// 1. landing renders + sets token cookie
[$code, $body] = req('GET', "$base/", [], $jar);
check($code === 200 && str_contains($body, "What's actually in your kitchen"), 'GET / renders pantry');
check (str_contains($body, 'pantry key'), 'token panel visible');

// 2. CSRF token scraped from form (needed for POSTs)
preg_match('/name="csrf" value="([a-f0-9]+)"/', $body, $cm);
$csrf = $cm[1] ?? '';
check($csrf !== '', 'csrf token present in form');

// 3. blocked internals behave like .htaccess (dev-router mirror)
[$code2] = req('GET', "$base/lib/config.example.php");
check($code2 === 404, 'lib/config.example.php blocked (404)');
[$code3] = req('GET', "$base/lib/db.php");
check($code3 === 404, 'lib/ blocked (404)');
[$code4] = req('GET', "$base/migrations/001_pantrypivot.sql");
check($code4 === 404, 'migrations/ blocked (404)');

// 4. add items via form POST (example pantry path)
[$code5] = req('POST', "$base/add", ['csrf' => $csrf, 'name' => 'flour', 'qty' => ''], $jar);
check($code5 === 303, 'POST /add redirects 303 (got ' . $code5 . ')');
req('POST', "$base/add", ['csrf' => $csrf, 'name' => 'butter'], $jar);
req('POST', "$base/add", ['csrf' => $csrf, 'name' => 'egg'], $jar);

// 5. synonym resolution: typed "spring onions" lands as the canonical scallion family
req('POST', "$base/add", ['csrf' => $csrf, 'name' => 'spring onions'], $jar);
[$code6, $body2] = req('GET', "$base/", [], $jar);
check($code6 === 200 && (str_contains($body2, 'scallion') || str_contains($body2, 'spring onions')),
    'spring onions resolves to a known vocabulary item');

// 6. verdicts page classifies (flour+butter+egg: pancakes=NEAR MISS w/ milk missing; omelette COOK NOW? no: salt missing)
[$code7, $vb] = req('GET', "$base/verdicts", [], $jar);
check($code7 === 200 && str_contains($vb, 'Pancakes'), 'verdicts lists Pancakes');
check(str_contains($vb, "Cook tonight"), 'cook tonight section present');
// omelette needs egg+butter+salt, we have egg+butter -> NEAR MISS
check(str_contains($vb, "You're close"), 'near-miss section present');
check(str_contains($vb, 'missing milk'), 'pancakes verdict names missing milk');

// 7. add milk -> pancakes becomes COOK NOW
req('POST', "$base/add", ['csrf' => $csrf, 'name' => 'milk'], $jar);
[$code8, $vb2] = req('GET', "$base/verdicts", [], $jar);
check(str_contains($vb2, 'nothing to buy'), 'pancakes cook-now after adding milk');

// 8. recipe detail with have/missing marks
[$code9, $rb] = req('GET', "$base/recipe/$stewId", [], $jar);
check($code9 === 200 && str_contains($rb, "honest list"), 'recipe detail renders');
check(str_contains($rb, 'class="ing have"'), 'have marks rendered');
check(str_contains($rb, 'class="ing missing"'), 'missing marks rendered');

// 9. API endpoints
[$code10, $sb] = req('GET', "$base/api/suggest?q=spri");
check($code10 === 200 && str_contains($sb, 'spring onions'), 'suggest API finds synonym');
[$code11, $pb] = req('GET', "$base/api/pantry", [], $jar);
check($code11 === 200 && str_contains($pb, '"flour"'), 'pantry API returns items');

// 10. remove item
[$code12] = req('POST', "$base/remove", ['csrf' => $csrf, 'ingredient_id' => 4], $jar);
check($code12 === 303, 'POST /remove redirects');

// 11. example pantry button
[$code13] = req('POST', "$base/example", ['csrf' => $csrf], $jar);
check($code13 === 303, 'example pantry loads');

// 12. import (paste key) flow: fresh token imports old pantry
$jar2 = '/tmp/pp-cookies2.txt';
@unlink($jar2);
[, $b2] = req('GET', "$base/", [], $jar2); // establish session+token for jar2
preg_match('/name="csrf" value="([a-f0-9]+)"/', $b2, $cm2);
$csrf2 = $cm2[1] ?? '';
[$code14] = req('POST', "$base/import", ['csrf' => $csrf2, 'token' => 'nonexistent0000000000abcdef'], $jar2);
check($code14 === 303, 'import with unknown key is harmless 303 (got ' . $code14 . ')');

// 13. 404 route
[$code15] = req('GET', "$base/nope");
check($code15 === 404, 'unknown route 404');

// 14. asset serving (css)
[$code16] = req('GET', "$base/assets/app.css");
check($code16 === 200 && str_contains((string)$code16, '200'), 'assets served');

proc_terminate($proc);
@unlink($jar);
@unlink($jar2);
// restore config absence for prod-shape testing? keep dev config; remove db
@unlink($dbFile);

echo "\n" . ($fail === 0 ? "ALL PASS" : "$fail FAIL") . ": $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);