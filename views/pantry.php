<?php
declare(strict_types=1);
/** PantryPivot — Pantry (home) view. Plain form POST; JS enhances (assets/app.js). */
/** @var \Pantry $pantry @var string $token @var array $config */
require __DIR__ . '/layout.php';

$items = $pantry->items($token);
$csrf  = csrf_token();
pp_layout_header('Your pantry', $token);
?>
<main>
  <h1>What's actually in your kitchen?</h1>
  <p class="muted">Type an ingredient. Quantities are optional.</p>

  <form method="post" action="/add" class="addbar" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= pp_e($csrf) ?>">
    <input type="text" name="name" id="add-name" placeholder="e.g. flour"
           required maxlength="80" autocomplete="off">
    <input type="text" name="qty" placeholder="qty (optional)" maxlength="80">
    <button type="submit">Add to pantry</button>
  </form>

  <?php if (!$items): ?>
    <section class="empty">
      <p>Your pantry is empty. Add a few things you have, or load an example
         pantry to see how the verdicts work.</p>
      <form method="post" action="/example">
        <input type="hidden" name="csrf" value="<?= pp_e($csrf) ?>">
        <button type="submit" class="secondary">Load example pantry</button>
      </form>
    </section>
  <?php else: ?>
    <p class="count"><?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?> in your pantry</p>
    <ul class="chips">
      <?php foreach ($items as $i): ?>
        <li class="chip">
          <span><?= pp_e($i['name']) ?><?= ($i['qty'] ?? '') !== '' ? ' <small>(' . pp_e($i['qty']) . ')</small>' : '' ?></span>
          <form method="post" action="/remove">
            <input type="hidden" name="csrf" value="<?= pp_e($csrf) ?>">
            <input type="hidden" name="ingredient_id" value="<?= (int)$i['ingredient_id'] ?>">
            <button type="submit" class="remove" aria-label="Remove <?= pp_e($i['name']) ?>">×</button>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
    <p><a href="/verdicts">Jump to your verdicts →</a></p>
  <?php endif; ?>

  <section class="token-panel">
    <h2>Save your pantry across devices</h2>
    <p class="muted">This is your pantry key. Copy it somewhere safe — it's the
       only way to move your pantry to another device. No account, no email.</p>
    <p><code class="token"><?= pp_e($token) ?></code></p>
    <form method="post" action="/import" class="importbar">
      <input type="hidden" name="csrf" value="<?= pp_e($csrf) ?>">
      <input type="text" name="token" placeholder="paste a pantry key here"
             pattern="[a-z0-9]{16,64}" maxlength="64">
      <button type="submit" class="secondary">Restore that pantry</button>
    </form>
  </section>
</main>
<?php pp_layout_footer(); ?>