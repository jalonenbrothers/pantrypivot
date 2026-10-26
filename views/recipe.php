<?php
declare(strict_types=1);
/** PantryPivot — Recipe detail view: have/missing marks + source link. */
/** @var \Pantry $pantry @var string $token @var array $config @var array $m */
require __DIR__ . '/layout.php';

$csrf = csrf_token();
$recipe = $pantry->recipe((int)$m[1]);
if (!$recipe) {
    http_response_code(404);
    pp_layout_header('Not found', $token);
    echo '<main><h1>Recipe not found</h1><p><a href="/verdicts">Back to your verdicts</a></p></main>';
    pp_layout_footer();
    return;
}
$ings = $pantry->recipeIngredients($recipe['id']);
$items = $pantry->items($token);
$haveIds = [];
foreach ($items as $i) {
    $haveIds[$i['ingredient_id']] = true;
}
$weights = $pantry->weights();
$score = MatchEngine::score($haveIds, $ings, $weights);
pp_layout_header((string)$recipe['name'], $token);
?>
<main>
  <p><a href="/verdicts">← Back to your verdicts</a></p>
  <h1><?= pp_e($recipe['name']) ?></h1>
  <p class="muted">
    <?= $recipe['category'] ? pp_e((string)$recipe['category']) : '' ?>
    <?= $recipe['area'] ? ' — ' . pp_e((string)$recipe['area']) : '' ?>
  </p>
  <p class="verdict big"><?= pp_e(MatchEngine::verdictLine($score, count($ings))) ?></p>
  <?php if ($recipe['thumbnail']): ?>
    <p><img src="<?= pp_e($recipe['thumbnail']) ?>" alt="" width="180" loading="lazy"></p>
  <?php endif; ?>

  <h2>Here's the honest list.</h2>
  <ul class="ing-list">
    <?php foreach ($ings as $ing): $have = isset($haveIds[(int)$ing['ingredient_id']]); ?>
      <li class="ing <?= $have ? 'have' : 'missing' ?>">
        <span class="mark"><?= $have ? '✓' : '✗' ?></span>
        <span class="ing-name"><?= pp_e($ing['name']) ?></span>
        <?php if (($ing['measure'] ?? '') !== ''): ?>
          <span class="measure">— <?= pp_e($ing['measure']) ?></span>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>

  <?php if ($score['missing'] > 0): ?>
    <p>Missing:
      <?php foreach (MatchEngine::sortMissing($score['missing_items']) as $mi): ?>
        <form method="post" action="/add" class="missing-chip">
          <input type="hidden" name="csrf" value="<?= pp_e($csrf) ?>">
          <input type="hidden" name="name" value="<?= pp_e($mi['name']) ?>">
          <button type="submit" class="chip-add">add <?= pp_e($mi['name']) ?></button>
        </form>
      <?php endforeach; ?>
    </p>
  <?php endif; ?>

  <?php if (!empty($recipe['source_url'])): ?>
    <p><a href="<?= pp_e($recipe['source_url']) ?>" rel="noopener">Full recipe at the source →</a></p>
  <?php endif; ?>
</main>
<?php pp_layout_footer(); ?>