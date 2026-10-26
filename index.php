<?php
declare(strict_types=1);

/**
 * PantryPivot — single front controller. All routes pretty, all output escaped.
 * Progressive enhancement: every mutation is a form POST that renders the
 * page again server-side; JS (assets/app.js) intercepts and does it async.
 */

require __DIR__ . '/lib/db.php';
require __DIR__ . '/src/Normalizer.php';
require __DIR__ . '/src/MatchEngine.php';
require __DIR__ . '/src/Pantry.php';

if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'cookie_secure'   => !empty($_SERVER['HTTPS']),
    ]);
}

try {
    $config = pp_config();
    $db = pp_db();
    $pantry = new Pantry($db);

    // ---- anonymous token (cookie) ----------------------------------------
    $token = $_COOKIE['pp_token'] ?? '';
    if (!preg_match('/^[a-z0-9]{16,64}$/', $token)) {
        $token = $pantry->newToken();
        $cookieOpts = ['expires' => time() + 86400 * 365, 'path' => '/',
                       'httponly' => true, 'samesite' => 'Lax'];
        if (!empty($_SERVER['HTTPS'])) {
            $cookieOpts['secure'] = true;
        }
        setcookie('pp_token', $token, $cookieOpts);
    } else {
        $pantry->ensureToken($token);
    }

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

    // ---- routes ------------------------------------------------------------
    // full verdict payload (used by views + /api/verdicts)
    $verdicts = static function () use ($pantry, $token): array {
        return pp_verdicts($pantry, $token);
    };

    if ($path === '/' || $path === '/pantry') {
        require __DIR__ . '/views/pantry.php';
        return;
    }
    if ($path === '/verdicts') {
        require __DIR__ . '/views/verdicts.php';
        return;
    }
    if (preg_match('#^/recipe/(\d+)$#', $path, $m)) {
        require __DIR__ . '/views/recipe.php';
        return;
    }
    if ($path === '/api/suggest' && $method === 'GET') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['suggestions' => $pantry->suggest((string)($_GET['q'] ?? ''))],
                         JSON_UNESCAPED_UNICODE);
        return;
    }
    if ($path === '/api/verdicts' && $method === 'GET') {
        // JSON snapshot of verdict data (used by app.js after adds)
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($verdicts(), JSON_UNESCAPED_UNICODE);
        return;
    }
    if ($path === '/api/pantry' && $method === 'GET') {
        header('Content-Type: application/json; charset=utf-8');
        $items = $pantry->items($token);
        echo json_encode(['items' => array_map(static fn ($i) => [
            'ingredient_id' => $i['ingredient_id'], 'name' => $i['name'], 'qty' => $i['qty'],
        ], $items), 'token' => $token], JSON_UNESCAPED_UNICODE);
        return;
    }

    // ---- mutations (form POST; JS intercepts) ----------------------------
    if ($path === '/add' && $method === 'POST') {
        csrf_check();
        $name = trim((string)($_POST['name'] ?? ''));
        $qty  = trim((string)($_POST['qty'] ?? ''));
        if ($name !== '') {
            $id = $pantry->resolveIngredient($name);
            if ($id === null) {
                // unknown word: pick best autocomplete hit, else reject softly
                $sugg = $pantry->suggest($name, 1);
                if ($sugg) {
                    $id = $pantry->resolveIngredient($sugg[0]['canonical']);
                }
            }
            if ($id !== null) {
                $pantry->add($token, $id, $qty !== '' ? $qty : null);
            }
        }
        redirect_after($isAjax);
        return;
    }
    if ($path === '/remove' && $method === 'POST') {
        csrf_check();
        $pantry->remove($token, (int)($_POST['ingredient_id'] ?? 0));
        redirect_after($isAjax);
        return;
    }
    if ($path === '/example' && $method === 'POST') {
        csrf_check();
        $pantry->clear($token);
        $example = $config['example_pantry'] ?? default_example_pantry();
        foreach ($example as [$name, $qty]) {
            $id = $pantry->resolveIngredient($name);
            if ($id !== null) {
                $pantry->add($token, $id, $qty ?: null);
            }
        }
        redirect_after($isAjax);
        return;
    }
    if ($path === '/import' && $method === 'POST') {
        csrf_check();
        $from = strtolower(trim((string)($_POST['token'] ?? '')));
        if (preg_match('/^[a-z0-9]{16,64}$/', $from)) {
            $pantry->importPantry($token, $from);
        }
        redirect_after($isAjax);
        return;
    }

    http_response_code(404);
    echo '<!doctype html><meta charset="utf-8"><title>Not found</title>'
       . '<body style="font-family:system-ui;max-width:600px;margin:2rem auto;padding:0 1rem">'
       . '<h1>Page not found</h1><p><a href="/">Back to your pantry</a></p></body>';
} catch (Throwable $e) {
    pp_fail($e);
}

// ---- helpers --------------------------------------------------------------

function csrf_check(): void
{
    // Same-site POST + Lax cookie is the MVP gate; token adds defense-in-depth.
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(403);
        echo '<!doctype html><meta charset="utf-8"><title>Blocked</title>'
           . '<body style="font-family:system-ui"><p>Session expired — go back and try again.</p></body>';
        exit;
    }
}

function redirect_after(bool $isAjax): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo '{"ok":true}';
        return;
    }
    header('Location: /verdicts', true, 303);
}

function default_example_pantry(): array
{
    return [
        ['salt', ''], ['black pepper', ''], ['cooking oil', ''], ['olive oil', ''],
        ['butter', ''], ['flour', ''], ['sugar', ''], ['garlic', ''], ['onion', ''],
        ['milk', ''], ['egg', ''], ['rice', ''], ['water', ''],
        ['tomato', ''], ['cheddar', ''], ['chicken', '500g'], ['pasta', ''], ['soy sauce', ''],
    ];
}

/** Full verdict payload for the token's pantry (used by views + api). */
function pp_verdicts(Pantry $pantry, string $token): array
{
    $items = $pantry->items($token);
    $haveIds = [];
    foreach ($items as $i) {
        $haveIds[$i['ingredient_id']] = $i;
    }
    $weights = $pantry->weights();
    $out = ['cook_now' => [], 'near_miss' => [], 'later' => [], 'unlock' => []];
    foreach ($pantry->recipes() as $r) {
        $ings = $pantry->recipeIngredients($r['id']);
        if (!$ings) {
            continue;
        }
        $score = MatchEngine::score($haveIds, $ings, $weights);
        $row = [
            'recipe' => ['id' => $r['id'], 'name' => $r['name'],
                         'thumbnail' => $r['thumbnail'], 'category' => $r['category']],
            'score' => $score,
            'total' => count($ings),
            'verdict' => MatchEngine::verdictLine($score, count($ings)),
        ];
        $out[$score['class']][] = $row;
    }
    // unlock ranking over near misses
    $out['unlock'] = MatchEngine::unlockRanking($out['near_miss']);
    // collapse LATER for JSON (list is potentially long)
    $out['later_count'] = count($out['later']);
    $out['later'] = array_slice($out['later'], 0, 50);
    return $out;
}