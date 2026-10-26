<?php
declare(strict_types=1);

/**
 * PantryPivot — config.php loader + PDO factory (MySQL prod, SQLite dev fallback).
 * Standard MVP-lab config shape (mvp-stack-standard): file returns an array.
 */

function pp_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $file = __DIR__ . '/config.php';
    $loaded = null;
    if (is_file($file)) {
        $loaded = @require $file;
    }
    $cfg = is_array($loaded) ? $loaded : null;
    if ($cfg === null) {
        $msg = 'config.php missing — copy lib/config.example.php to lib/config.php '
             . 'and fill in your database credentials. See README Deploy section.';
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $msg . "\n");
            exit(1);
        }
        http_response_code(500);
        echo '<!doctype html><meta charset="utf-8"><title>Setup needed</title>'
           . '<body style="font-family:system-ui;max-width:600px;margin:2rem auto;padding:0 1rem">'
           . '<h1>Setup needed</h1><p>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p></body>';
        exit;
    }
    return $cfg;
}

/** PDO for the app database. SQLite dev fallback when db.name starts with 'sqlite:'. */
function pp_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $db = pp_config()['db'];
    if (str_starts_with((string)$db['name'], 'sqlite:')) {
        $pdo = new PDO('sqlite:' . __DIR__ . '/' . substr((string)$db['name'], 7), null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } else {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $db['host'], (int)($db['port'] ?? 3306), $db['name']);
        $pdo = new PDO($dsn, (string)$db['user'], (string)$db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

/** debug flag from config, default false. */
function pp_debug(): bool
{
    return (bool)(pp_config()['debug'] ?? false);
}

/** Escape for HTML output. */
function pp_e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** Top-level exception handler honoring debug flag (never leaks errors when debug=false). */
function pp_fail(Throwable $e): void
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $e->getMessage() . "\n");
        if (pp_debug()) {
            fwrite(STDERR, $e->getTraceAsString() . "\n");
        }
        exit(1);
    }
    http_response_code(500);
    $body = '<!doctype html><meta charset="utf-8"><title>Something went wrong</title>'
          . '<body style="font-family:system-ui;max-width:600px;margin:2rem auto;padding:0 1rem">'
          . '<h1>Something went wrong</h1>';
    if (pp_debug()) {
        $body .= '<pre style="white-space:pre-wrap;background:#f5f5f5;padding:1rem;border-radius:6px">'
               . pp_e(get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString())
               . '</pre>';
    }
    $body .= '</body>';
    echo $body;
}