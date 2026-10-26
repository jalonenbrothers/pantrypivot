<?php
declare(strict_types=1);
/** PantryPivot — Verdicts view (the payoff screen). */
/** @var \Pantry $pantry @var string $token @var array $config */
require __DIR__ . '/layout.php';

$csrf = csrf_token();
$data = pp_verdicts($pantry, $token);
pp_layout_header('Your verdicts', $token);
$items = $pantry->items($token);
?>
<main>
  <h1>Your verdicts</h1>
  <?php if (count($items) < 3): ?>
    <p class="muted">Add at least 3 things to your pantry for useful verdicts.</p>
  <?php endif; ?>
  <p><a href="/">← Your pantry (<?= count($items) ?> items)</a></p>

  <section id="verdicts">
    <h2>Cook tonight</h2>
    <p class="muted">Nothing to buy.</p>
    <?php if (!$data['cook_now']): ?><p class="muted">Nothing yet — your pantry is still building up.</p><?php endif; ?>
    <ul class="recipe-list">
      <?php foreach ($data['cook_now'] as $row): ?>
        <li class="recipe-row">
          <a class="recipe-link" href="/recipe/<?= (int)$row['recipe']['id'] ?>">
            <?= pp_e($row['recipe']['name']) ?></a>
          <span class="verdict"><?= pp_e($row['verdict']) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>

    <h2>You're close</h2>
    <p class="muted">You're 1-2 ingredients away.</p>
    <?php if ($data['unlock']): ?>
      <ul class="unlock-list">
        <?php foreach ($data['unlock'] as $name => $count): ?>
          <?php if ($count < 2) { break; } // headline = multi-meal unlocks only ?>
          <li>
            <form method="post" action="/add" class="unlock-add">
              <input type="hidden" name="csrf" value="<?= pp_e($csrf) ?>">
              <input type="hidden" name="name" value="<?= pp_e($name) ?>">
              <button type="submit" class="secondary">Add <?= pp_e($name) ?></button>
            </form>
            <span>→ <?= (int)$count ?> more meal<?= $count === 1 ? '' : 's' ?> open up</span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <ul class="recipe-list">
      <?php foreach ($data['near_miss'] as $row): ?>
        <li class="recipe-row">
          <a class="recipe-link" href="/recipe/<?= (int)$row['recipe']['id'] ?>">
            <?= pp_e($row['recipe']['name']) ?></a>
          <span class="verdict"><?= pp_e($row['verdict']) ?></span>
          <?php foreach (MatchEngine::sortMissing($row['score']['missing_items']) as $m): ?>
            <form method="post" action="/add" class="missing-chip">
              <input type="hidden" name="csrf" value="<?= pp_e($csrf) ?>">
              <input type="hidden" name="name" value="<?= pp_e($m['name']) ?>">
              <button type="submit" class="chip-add">add <?= pp_e($m['name']) ?></button>
            </form>
          <?php endforeach; ?>
        </li>
      <?php endforeach; ?>
    </ul>

    <h2>Later, maybe</h2>
    <p class="muted"><?= (int)($data['later_count'] ?? 0) ?> recipes need 3 or more items
       you don't have. They'll move up as your pantry grows.</p>
    <details>
      <summary>Show them anyway</summary>
      <ul class="recipe-list">
        <?php foreach ($data['later'] as $row): ?>
          <li class="recipe-row">
            <a class="recipe-link" href="/recipe/<?= (int)$row['recipe']['id'] ?>">
              <?= pp_e($row['recipe']['name']) ?></a>
            <span class="verdict"><?= pp_e($row['verdict']) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </details>
  </section>
</main>
<?php pp_layout_footer(); ?>