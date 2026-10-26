<?php
declare(strict_types=1);
/**
 * PantryPivot — shared layout helpers. Called by views via require from index.php context.
 * Kept as functions to stay framework-free and template-friendly.
 */

function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        if (PHP_SAPI !== 'cli') {
            session_start();
        }
    }
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function pp_layout_header(string $title, string $token): void
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>' . pp_e($title) . ' — PantryPivot</title>'
       . '<link rel="stylesheet" href="/assets/app.css">'
       . '<script src="/assets/app.js" defer></script>'
       . '</head><body>'
       . '<header><nav><a class="brand" href="/">PantryPivot</a>'
       . '<a href="/">Pantry</a> <a href="/verdicts">Verdicts</a></nav></header>';
}

function pp_layout_footer(): void
{
    echo '<footer><p>What you have decides what you cook. '
       . 'Recipe data cached locally from TheMealDB (free test key) — '
       . 'see the recipe page for the source link.</p></footer></body></html>';
}