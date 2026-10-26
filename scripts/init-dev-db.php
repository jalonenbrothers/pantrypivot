<?php
// dev helper: initialize empty sqlite db with the test schema
// run from repo root:
//   php scripts/init-dev-db.php            -> lib/pantrypivot.sqlite
//   php scripts/init-dev-db.php --test     -> lib/pantrypivot-test.sqlite (tests use this)
$root = dirname(__DIR__);
$db = $root . '/lib/pantrypivot.sqlite';
if (in_array('--test', $argv ?? [], true)) {
    $db = $root . '/lib/pantrypivot-test.sqlite';
}
@unlink($db);
$pdo = new PDO('sqlite:' . $db);
$pdo->exec(file_get_contents($root . '/tests/schema.sqlite.sql'));
echo "initialized $db\n";
