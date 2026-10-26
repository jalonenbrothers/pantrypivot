<?php
declare(strict_types=1);

/**
 * PantryPivot — dev router for `php -S`. Static files served directly;
 * everything else goes through index.php exactly like production .htaccess.
 * NOT used in production (Hostinger Apache picks up .htaccess).
 */

if (PHP_SAPI !== 'cli-server') {
    return false;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// block internals FIRST (production .htaccess order of operations)
if (preg_match('#^/(lib|src|tests|migrations|scripts|data)(/|$)#', $path)
    || $path === '/config.example.php') {
    http_response_code(404);
    echo "Not found";
    return true;
}
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file)) {
    return false; // serve asset as-is
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/index.php';
return true;