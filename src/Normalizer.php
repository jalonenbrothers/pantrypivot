<?php
declare(strict_types=1);

/**
 * PantryPivot — ingredient name normalization (import time + pantry input time).
 *
 * Canonicalization: lowercase, trim, collapse whitespace, strip punctuation.
 * Synonym mapping is data-driven from the `synonyms` table (seeded at import
 * from Open Food Facts + curated pairs), so this class only needs the map.
 */
final class Normalizer
{
    /** Bare canonical form of an ingredient string. */
    public static function canonical(string $raw): string
    {
        $s = mb_strtolower(trim($raw), 'UTF-8');
        $s = preg_replace('/[\.,;:!?\-–—_\/()]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return trim($s);
    }

    /**
     * Resolve a raw name to a canonical name via a synonym map.
     * @param array<string,string> $synonyms synonym => canonical name
     */
    public static function resolve(string $raw, array $synonyms): ?string
    {
        $c = self::canonical($raw);
        if ($c === '') {
            return null;
        }
        return $synonyms[$c] ?? null;
    }

    /**
     * Build the full synonym map rows for import: known alias pairs used when
     * TheMealDB names don't match common pantry language. Static seed list —
     * Open Food Facts lookup enriches per-name at import (see scripts/).
     *
     * @return array<int,array{synonym:string,canonical:string}>
     */
    public static function seedSynonyms(): array
    {
        $pairs = [
            // TheMealDB string        => canonical pantry word
            ['spring onion',            'scallion'],
            ['spring onions',          'scallion'],
            ['green onion',            'scallion'],
            ['green onions',           'scallion'],
            ['aubergine',              'eggplant'],
            ['courgette',              'zucchini'],
            ['courgettes',             'zucchini'],
            ['bell pepper',            'capsicum'],
            ['red pepper',             'capsicum'],
            ['green pepper',           'capsicum'],
            ['yellow pepper',          'capsicum'],
            ['capsicum',               'capsicum'],
            ['garlic cloves',          'garlic'],
            ['garlic clove',           'garlic'],
            ['cloves garlic',          'garlic'],
            ['tomatoes',               'tomato'],
            ['chopped tomatoes',       'tomato'],
            ['plum tomatoes',          'tomato'],
            ['cherry tomatoes',        'tomato'],
            ['potatoes',               'potato'],
            ['new potatoes',           'potato'],
            ['carrots',                'carrot'],
            ['onions',                 'onion'],
            ['red onions',             'onion'],
            ['white onions',           'onion'],
            ['shallots',               'shallot'],
            ['lemons',                 'lemon'],
            ['limes',                  'lime'],
            ['eggs',                   'egg'],
            ['egg',                    'egg'],
            ['egg yolks',              'egg'],
            ['egg whites',             'egg'],
            ['chicken breast',         'chicken'],
            ['chicken breasts',        'chicken'],
            ['chicken thighs',         'chicken'],
            ['chicken thigh',          'chicken'],
            ['chicken drumsticks',     'chicken'],
            ['minced beef',            'ground beef'],
            ['minced pork',            'ground pork'],
            ['minced lamb',            'ground lamb'],
            ['beef mince',             'ground beef'],
            ['pork mince',             'ground pork'],
            ['lamb mince',             'ground lamb'],
            ['plain flour',            'flour'],
            ['self raising flour',     'flour'],
            ['self-raising flour',     'flour'],
            ['all purpose flour',      'flour'],
            ['all-purpose flour',      'flour'],
            ['whole wheat flour',      'flour'],
            ['creme fraiche',          'sour cream'],
            ['crème fraîche',          'sour cream'],
            ['double cream',           'cream'],
            ['single cream',           'cream'],
            ['heavy cream',            'cream'],
            ['whipping cream',         'cream'],
            ['cheddar cheese',         'cheddar'],
            ['cheddar cheese grated',  'cheddar'],
            ['parmesan cheese',        'parmesan'],
            ['parmigiano reggiano',    'parmesan'],
            ['fetta cheese',           'feta'],
            ['feta cheese',            'feta'],
            ['mozzarella cheese',     'mozzarella'],
            ['basil leaves',           'basil'],
            ['fresh basil',            'basil'],
            ['coriander',              'cilantro'],
            ['fresh coriander',        'cilantro'],
            ['cilantro',               'cilantro'],
            ['ground cumin',          'cumin'],
            ['ground cinnamon',        'cinnamon'],
            ['ground turmeric',        'turmeric'],
            ['olive oil',              'olive oil'],
            ['vegetable oil',          'cooking oil'],
            ['sunflower oil',          'cooking oil'],
            ['canola oil',             'cooking oil'],
            ['rapeseed oil',           'cooking oil'],
            ['cooking oil',            'cooking oil'],
            ['salt',                   'salt'],
            ['sea salt',               'salt'],
            ['kosher salt',            'salt'],
            ['table salt',             'salt'],
            ['salt and pepper',        'salt'],
            ['black pepper',           'black pepper'],
            ['ground black pepper',    'black pepper'],
            ['pepper',                 'black pepper'],
            ['water',                 'water'],
            ['boiling water',          'water'],
            ['cold water',             'water'],
            ['warm water',             'water'],
            ['butter',                 'butter'],
            ['unsalted butter',        'butter'],
            ['salted butter',          'butter'],
            ['milk',                  'milk'],
            ['whole milk',             'milk'],
            ['skimmed milk',           'milk'],
            ['skim milk',              'milk'],
            ['white wine',             'white wine'],
            ['red wine',               'red wine'],
            ['white sugar',            'sugar'],
            ['caster sugar',           'sugar'],
            ['granulated sugar',       'sugar'],
            ['brown sugar',            'sugar'],
            ['icing sugar',            'sugar'],
            ['chicken stock',          'chicken stock'],
            ['chicken broth',          'chicken stock'],
            ['beef stock',             'beef stock'],
            ['beef broth',             'beef stock'],
            ['vegetable stock',        'vegetable stock'],
            ['vegetable broth',        'vegetable stock'],
            ['soy sauce',              'soy sauce'],
            ['dried oregano',          'oregano'],
            ['dried thyme',            'thyme'],
            ['dried rosemary',         'rosemary'],
            ['fresh parsley',          'parsley'],
            ['baking potatoes',        'potato'],
            ['button mushrooms',       'mushrooms'],
            ['mushrooms',             'mushrooms'],
            ['white rice',             'rice'],
            ['basmati rice',           'rice'],
            ['long grain rice',        'rice'],
            ['spaghetti',              'spaghetti'],
            ['penne',                  'pasta'],
            ['penne pasta',            'pasta'],
            ['macaroni',               'pasta'],
            ['linguine',               'pasta'],
            ['fusilli',                'pasta'],
            ['tagliatelle',            'pasta'],
        ];
        $out = [];
        foreach ($pairs as [$syn, $can]) {
            $out[] = ['synonym' => self::canonical($syn), 'canonical' => self::canonical($can)];
        }
        return $out;
    }

    /**
     * Staples (weight 1) for the tunable weight table — things most kitchens
     * have, so their absence should NOT push a recipe out of reach in ranking.
     * Everything not on this list defaults to weight 2.
     *
     * @return string[]
     */
    public static function staples(): array
    {
        return [
            'salt', 'black pepper', 'water', 'cooking oil', 'olive oil', 'butter',
            'sugar', 'flour', 'milk', 'garlic', 'onion', 'vinegar', 'soy sauce',
        ];
    }
}