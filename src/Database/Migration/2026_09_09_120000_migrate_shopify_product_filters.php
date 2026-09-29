<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const string ENTITY_TYPE = 'shopifyProduct';

    private const array RENAMED = [
        'channel'  => 'channels',
        'currency' => 'currencies',
    ];

    public function up(): void
    {
        $this->each(function (array $filters): array {
            if (array_key_exists('productfilter', $filters)) {
                $skus = array_values(array_filter(
                    array_map(trim(...), preg_split('/[\s,]+/', (string) $filters['productfilter']) ?: []),
                    fn (string $sku): bool => $sku !== ''
                ));

                if ($skus !== []) {
                    $filters['sku'] = $skus;
                }

                unset($filters['productfilter']);
            }

            if (array_key_exists('productstatus', $filters)) {
                $status = trim((string) $filters['productstatus']);

                if (in_array($status, ['enable', 'disable'], true)) {
                    $filters['status'] = $status;
                }

                unset($filters['productstatus']);
            }

            foreach (self::RENAMED as $old => $new) {
                if (! array_key_exists($old, $filters)) {
                    continue;
                }

                if ($filters[$old] !== null && $filters[$old] !== '') {
                    $filters[$new] = $filters[$old];
                }

                unset($filters[$old]);
            }

            return $filters;
        });
    }

    public function down(): void
    {
        $this->each(function (array $filters): array {
            if (array_key_exists('sku', $filters)) {
                $filters['productfilter'] = implode(',', (array) $filters['sku']);

                unset($filters['sku']);
            }

            if (array_key_exists('status', $filters)) {
                $status = (string) $filters['status'];

                if (in_array($status, ['enable', 'disable'], true)) {
                    $filters['productstatus'] = $status;
                }

                unset($filters['status']);
            }

            foreach (self::RENAMED as $old => $new) {
                if (! array_key_exists($new, $filters)) {
                    continue;
                }

                $filters[$old] = $filters[$new];

                unset($filters[$new]);
            }

            return $filters;
        });
    }

    /**
     * Rewrite the filters JSON of every Shopify product export profile.
     *
     * Chunked by id so a large job_instances table is not loaded at once.
     */
    private function each(callable $rewrite): void
    {
        DB::table('job_instances')
            ->where('entity_type', self::ENTITY_TYPE)
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($rewrite): void {
                foreach ($rows as $row) {
                    $filters = json_decode((string) $row->filters, true);

                    if (! is_array($filters)) {
                        continue;
                    }

                    $updated = $rewrite($filters);

                    if ($updated === $filters) {
                        continue;
                    }

                    DB::table('job_instances')
                        ->where('id', $row->id)
                        ->update(['filters' => json_encode($updated)]);
                }
            });
    }
};
