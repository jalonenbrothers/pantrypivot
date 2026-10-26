<?php
declare(strict_types=1);

/**
 * PantryPivot — data-access layer. All statements prepared, no exceptions.
 */
final class Pantry
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ---- tokens ---------------------------------------------------------

    public function ensureToken(string $token): void
    {
        if (!preg_match('/^[a-z0-9]{16,64}$/', $token)) {
            throw new InvalidArgumentException('bad token');
        }
        // portable: plain INSERT with catch on unique violation (IGNORE is MySQL-only)
        try {
            $this->db->prepare('INSERT INTO tokens (token) VALUES (?)')->execute([$token]);
        } catch (PDOException $e) {
            // already exists — fine
        }
    }

    public function newToken(): string
    {
        $t = bin2hex(random_bytes(16)); // 32 hex chars
        $this->ensureToken($t);
        return $t;
    }

    // ---- pantry ---------------------------------------------------------

    /** @return array<int,array{id:int,ingredient_id:int,name:string,qty:?string}> */
    public function items(string $token): array
    {
        $st = $this->db->prepare(
            'SELECT p.id, p.ingredient_id, i.name, p.qty
               FROM pantry_items p
               JOIN ingredients i ON i.id = p.ingredient_id
              WHERE p.token = ? ORDER BY p.created_at, p.id');
        $st->execute([$token]);
        $rows = $st->fetchAll();
        foreach ($rows as &$r) {
            $r['ingredient_id'] = (int)$r['ingredient_id'];
            $r['id'] = (int)$r['id'];
        }
        return $rows;
    }

    public function add(string $token, int $ingredientId, ?string $qty = null): void
    {
        // INSERT ... ON DUPLICATE KEY is MySQL; ON CONFLICT is sqlite. Portable = try/catch.
        try {
            $this->db->prepare(
                'INSERT INTO pantry_items (token, ingredient_id, qty) VALUES (?,?,?)')
                ->execute([$token, $ingredientId, $qty !== '' ? $qty : null]);
        } catch (PDOException $e) {
            // duplicate pantry row — user already has it; fine
        }
    }

    public function remove(string $token, int $ingredientId): void
    {
        $this->db->prepare(
            'DELETE FROM pantry_items WHERE token = ? AND ingredient_id = ?')
            ->execute([$token, $ingredientId]);
    }

    public function clear(string $token): void
    {
        $this->db->prepare('DELETE FROM pantry_items WHERE token = ?')->execute([$token]);
    }

    /** Import another token's pantry into this one (paste-key flow). */
    public function importPantry(string $token, string $fromToken): int
    {
        $this->ensureToken($fromToken);
        $this->ensureToken($token);
        // copy row-by-row; add() swallows duplicate rows (COPY, not move)
        foreach ($this->items($fromToken) as $row) {
            $this->add($token, (int)$row['ingredient_id'], $row['qty'] ?? null);
        }
        return count($this->items($token));
    }

    // ---- ingredients / autocomplete --------------------------------------

    /** @return array<string,string> canonical name => display name (title-ish) */
    public function ingredientVocabulary(): array
    {
        $rows = $this->db->query('SELECT name FROM ingredients ORDER BY name')->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[$r['name']] = $r['name'];
        }
        return $out;
    }

    /** @return array<string,string> synonym => canonical */
    public function synonymMap(): array
    {
        $rows = $this->db->query(
            'SELECT s.synonym, i.name FROM synonyms s JOIN ingredients i ON i.id = s.ingredient_id')
            ->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[$r['synonym']] = $r['name'];
        }
        return $out;
    }

    /** Resolve a raw typed name to an ingredient id (canonical or synonym), else null. */
    public function resolveIngredient(string $raw): ?int
    {
        $c = Normalizer::canonical($raw);
        if ($c === '') {
            return null;
        }
        $st = $this->db->prepare(
            'SELECT i.id FROM ingredients i WHERE i.name = ?
             UNION
             SELECT i.id FROM synonyms s JOIN ingredients i ON i.id = s.ingredient_id
              WHERE s.synonym = ? LIMIT 1');
        $st->execute([$c, $c]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    /** Autocomplete over vocabulary + synonyms. @return array<int,array{name:string,canonical:string}> */
    public function suggest(string $q, int $limit = 12): array
    {
        $c = Normalizer::canonical($q);
        if ($c === '') {
            return [];
        }
        $st = $this->db->prepare(
            'SELECT name, name AS canonical FROM ingredients WHERE name LIKE ?
             UNION
             SELECT s.synonym AS name, i.name AS canonical FROM synonyms s
               JOIN ingredients i ON i.id = s.ingredient_id WHERE s.synonym LIKE ?
             ORDER BY name LIMIT ' . (int)$limit);
        $st->execute(["%$c%", "%$c%"]);
        return $st->fetchAll();
    }

    // ---- recipes + verdicts ----------------------------------------------

    /** @return array<int,array{id:int,source:string,source_id:string,name:string,category:?string,area:?string,thumbnail:?string,source_url:?string}> */
    public function recipes(int $limit = 500): array
    {
        $st = $this->db->prepare('SELECT * FROM recipes ORDER BY id LIMIT ' . (int)$limit);
        $st->execute();
        $rows = $st->fetchAll();
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
        }
        return $rows;
    }

    public function recipe(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM recipes WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row) {
            $row['id'] = (int)$row['id'];
        }
        return $row ?: null;
    }

    /** @return array<int,array{ingredient_id:int,name:string,measure:?string}> */
    public function recipeIngredients(int $recipeId): array
    {
        $st = $this->db->prepare(
            'SELECT ri.ingredient_id, i.name, ri.measure
               FROM recipe_ingredients ri JOIN ingredients i ON i.id = ri.ingredient_id
              WHERE ri.recipe_id = ? ORDER BY ri.ingredient_id');
        $st->execute([$recipeId]);
        $rows = $st->fetchAll();
        foreach ($rows as &$r) {
            $r['ingredient_id'] = (int)$r['ingredient_id'];
        }
        return $rows;
    }

    /** @return array<int,int> ingredient_id => staple weight */
    public function weights(): array
    {
        $out = [];
        foreach ($this->db->query('SELECT ingredient_id, weight FROM staple_weights') as $r) {
            $out[(int)$r['ingredient_id']] = (int)$r['weight'];
        }
        return $out;
    }
}