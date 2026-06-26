<?php

namespace Webkul\Shopify\Services\Taxonomy;

use RuntimeException;

class ShopifyTaxonomyLoader
{
    private const SEARCH_LIMIT = 50;

    /** @var array<int, array{id: string, path: string, depth: int}>|null */
    private ?array $entries = null;

    /**
     * Load + cache all taxonomy entries from the bundled file.
     *
     * Source format (Shopify product-taxonomy categories.txt):
     *   gid://shopify/TaxonomyCategory/ap-1   : Animals & Pet Supplies > Live Animals
     *
     * @return array<int, array{id: string, path: string, depth: int}>
     */
    public function all(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        $path = (string) config('shopify_taxonomy.taxonomy_file');

        if ($path === '' || ! is_readable($path)) {
            throw new RuntimeException("Shopify taxonomy file not found or unreadable: {$path}");
        }

        $entries = [];
        $handle = fopen($path, 'r');

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);

                if ($line === '' || $line[0] === '#') {
                    continue;
                }

                // First whitespace-free token is the GID (contains no spaces); the
                // path follows the aligned `:` separator.
                if (! preg_match('/^(\S+)\s*:\s*(.+)$/', $line, $m)) {
                    continue;
                }

                $id = trim($m[1]);
                $taxPath = trim($m[2]);

                if ($id === '' || $taxPath === '') {
                    continue;
                }

                $entries[] = [
                    'id' => $id,
                    'path' => $taxPath,
                    'depth' => substr_count($taxPath, ' > ') + 1,
                ];
            }
        } finally {
            fclose($handle);
        }

        return $this->entries = $entries;
    }

    /**
     * Case-insensitive substring search on the full path. Empty query returns the first SEARCH_LIMIT.
     *
     * @return array<int, array{id: string, path: string, depth: int}>
     */
    public function search(string $query): array
    {
        $entries = $this->all();
        $query = trim($query);

        if ($query === '') {
            return array_slice($entries, 0, self::SEARCH_LIMIT);
        }

        $needle = strtolower($query);
        $hits = [];

        foreach ($entries as $entry) {
            if (str_contains(strtolower($entry['path']), $needle)) {
                $hits[] = $entry;

                if (count($hits) >= self::SEARCH_LIMIT) {
                    break;
                }
            }
        }

        return $hits;
    }

    /**
     * Exact GID lookup.
     *
     * @return array{id: string, path: string, depth: int}|null
     */
    public function findById(string $id): ?array
    {
        foreach ($this->all() as $entry) {
            if ($entry['id'] === $id) {
                return $entry;
            }
        }

        return null;
    }

    /** @var array<string, array{id: string, name: string, parent: string}>|null */
    private ?array $index = null;

    /** @var array<string, array<int, string>>|null */
    private ?array $childrenByParent = null;

    private function buildIndex(): void
    {
        if ($this->index !== null) {
            return;
        }

        $this->index = [];
        $this->childrenByParent = [];

        foreach ($this->all() as $entry) {
            $short = substr($entry['id'], strrpos($entry['id'], '/') + 1);
            $parent = str_contains($short, '-') ? substr($short, 0, strrpos($short, '-')) : '';
            $name = str_contains($entry['path'], ' > ')
                ? substr($entry['path'], strrpos($entry['path'], ' > ') + 3)
                : $entry['path'];

            $this->index[$short] = ['id' => $entry['id'], 'name' => $name, 'parent' => $parent];
            $this->childrenByParent[$parent][] = $short;
        }
    }

    /**
     * @return array<int, array{id: string, name: string, hasChildren: bool}>
     */
    public function topLevel(): array
    {
        return $this->childrenOfShort('');
    }

    /**
     * @return array<int, array{id: string, name: string, hasChildren: bool}>
     */
    public function children(string $gid): array
    {
        return $this->childrenOfShort(substr($gid, strrpos($gid, '/') + 1));
    }

    /**
     * @return array<int, array{id: string, name: string, hasChildren: bool}>
     */
    private function childrenOfShort(string $parentShort): array
    {
        $this->buildIndex();

        $rows = [];

        foreach ($this->childrenByParent[$parentShort] ?? [] as $short) {
            $rows[] = [
                'id' => $this->index[$short]['id'],
                'name' => $this->index[$short]['name'],
                'hasChildren' => ! empty($this->childrenByParent[$short]),
            ];
        }

        usort($rows, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    public function descendants(string $gid): array
    {
        $this->buildIndex();

        $short = substr($gid, strrpos($gid, '/') + 1);

        if (! isset($this->index[$short])) {
            return [];
        }

        $ids = [$this->index[$short]['id']];
        $stack = [$short];

        while ($stack !== []) {
            $current = array_pop($stack);

            foreach ($this->childrenByParent[$current] ?? [] as $childShort) {
                $ids[] = $this->index[$childShort]['id'];
                $stack[] = $childShort;
            }
        }

        return $ids;
    }

    /**
     * @param  array<int, string>  $gids
     * @return array<string, string>
     */
    public function namesFor(array $gids): array
    {
        $this->buildIndex();

        $names = [];

        foreach ($gids as $gid) {
            $short = substr($gid, strrpos($gid, '/') + 1);

            if (isset($this->index[$short])) {
                $names[$gid] = $this->index[$short]['name'];
            }
        }

        return $names;
    }
}
