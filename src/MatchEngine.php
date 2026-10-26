<?php
declare(strict_types=1);

/**
 * PantryPivot — deterministic set-score match engine.
 *
 * Classification (locked scope):
 *   COOK NOW  — nothing missing
 *   NEAR MISS — exactly 1-2 ingredients missing
 *   LATER     — 3+ missing
 *
 * Pure functions: everything is computed from arrays so the whole class is
 * unit-testable without a database (tests/run.php).
 */
final class MatchEngine
{
    public const CLASS_COOK_NOW = 'cook_now';
    public const CLASS_NEAR_MISS = 'near_miss';
    public const CLASS_LATER = 'later';

    /**
     * @param array<int,array{ingredient_id:int,name:string,weight?:int}> $pantry ingredient_id => row
     * @param array<int,array{ingredient_id:int,name:string,weight?:int,measure?:?string}> $recipeIngredients
     * @param array<int,int> $weights ingredient_id => staple weight (1=staple, 2+=counts heavier)
     * @return array{have:int,missing:int,class:string,missing_items:array<int,array{name:string,weight:int}>}
     */
    public static function score(array $pantry, array $recipeIngredients, array $weights): array
    {
        $haveIds = [];
        foreach ($pantry as $row) {
            $haveIds[(int)$row['ingredient_id']] = true;
        }
        $missing = [];
        $have = 0;
        foreach ($recipeIngredients as $ing) {
            $id = (int)$ing['ingredient_id'];
            if (isset($haveIds[$id])) {
                $have++;
            } else {
                $missing[$id] = [
                    'name'   => (string)$ing['name'],
                    // heavier weight = less likely to be in anyone's cupboard = counts heavier when ranking
                    'weight' => (int)($weights[$id] ?? ($ing['weight'] ?? 2)),
                ];
            }
        }
        $missingCount = count($missing);
        $class = match (true) {
            $missingCount === 0 => self::CLASS_COOK_NOW,
            $missingCount <= 2  => self::CLASS_NEAR_MISS,
            default             => self::CLASS_LATER,
        };
        return [
            'have'         => $have,
            'missing'      => $missingCount,
            'class'        => $class,
            'missing_items' => array_values($missing),
        ];
    }

    /**
     * Verdict line: "You have 7/9 — missing flour, butter."
     */
    public static function verdictLine(array $score, int $totalIngredients): string
    {
        if ($score['missing'] === 0) {
            return sprintf('You have %d/%d — nothing to buy.', $score['have'], $totalIngredients);
        }
        $names = array_map(static fn ($m) => $m['name'], $score['missing_items']);
        return sprintf('You have %d/%d — missing %s.',
            $score['have'], $totalIngredients, implode(', ', $names));
    }

    /**
     * Near-miss unlock surface: group near-miss recipes by their missing set.
     * Returns item names ranked by how many meals a single addition unlocks:
     *   ['flour' => 4, 'butter' => 2]
     * Only single-item unlocks count toward the headline number ("add flour →
     * 4 meals tonight"); multi-item groups still list their recipes.
     *
     * @param array<int,array{recipe:array{id:int,name:string,thumbnail:?string},score:array}> $nearMisses
     * @return array<string,int> name => meals unlocked
     */
    public static function unlockRanking(array $nearMisses): array
    {
        $counts = [];
        foreach ($nearMisses as $row) {
            $items = $row['score']['missing_items'] ?? [];
            $names = array_map(static fn ($m) => (string)$m['name'], $items);
            foreach ($names as $name) {
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }
        arsort($counts);
        return $counts;
    }

    /**
     * Sort missing items for display: heaviest (least-likely-in-cupboard, i.e.
     * fresh/protein items) first — those are the ones worth telling the cook
     * about; staples (oil/salt/pepper) sink to the end.
     */
    public static function sortMissing(array $missingItems): array
    {
        usort($missingItems, static function ($a, $b) {
            [$wa, $wb] = [(int)$a['weight'], (int)$b['weight']];
            return $wb <=> $wa ?: strcasecmp((string)$a['name'], (string)$b['name']);
        });
        return $missingItems;
    }
}