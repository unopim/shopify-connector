<?php

namespace Webkul\Shopify\Helpers\Iterator;

use Webkul\Shopify\Traits\ShopifyGraphqlRequest;

class MetaobjectIterator implements \Iterator
{
    use ShopifyGraphqlRequest;

    /** @var array<int, array<string, mixed>> */
    private array $rows = [];

    private int $currentKey = 0;

    public function __construct(private array $credential)
    {
        $this->rows = $this->buildRows();
    }

    public function current(): mixed
    {
        return $this->rows[$this->currentKey] ?? null;
    }

    public function key(): mixed
    {
        return $this->currentKey;
    }

    public function next(): void
    {
        $this->currentKey++;
    }

    public function rewind(): void
    {
        $this->currentKey = 0;
    }

    public function valid(): bool
    {
        return isset($this->rows[$this->currentKey]);
    }

    /**
     * Fetch every definition, reverse its Shopify field definitions into local
     * field structures and return them ordered child-first so nested references
     * resolve during import.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildRows(): array
    {
        $definitions = $this->fetchDefinitions();
        $typeByGid = [];

        foreach ($definitions as $definition) {
            $typeByGid[$definition['id']] = $definition['type'];
        }

        $rows = [];

        foreach ($definitions as $definition) {
            $detail = $this->requestGraphQlApiAction('metaobjectDefinitionByType', $this->credential, ['type' => $definition['type']]);
            $node = $detail['body']['data']['metaobjectDefinitionByType'] ?? null;

            if (! $node) {
                continue;
            }

            $rows[$definition['type']] = [
                'id' => $node['id'],
                'type' => $node['type'],
                'name' => $node['name'] ?? $node['type'],
                'fields' => $this->reverseFields($node['fieldDefinitions'] ?? [], $typeByGid),
            ];
        }

        return $this->sortChildFirst($rows);
    }

    /**
     * @return array<int, array{id: string, type: string, name: string}>
     */
    private function fetchDefinitions(): array
    {
        $definitions = [];
        $cursor = null;

        do {
            $variables = ['first' => 50];

            if ($cursor) {
                $variables['after'] = $cursor;
            }

            $response = $this->requestGraphQlApiAction('metaobjectDefinitions', $this->credential, $variables);
            $edges = $response['body']['data']['metaobjectDefinitions']['edges'] ?? [];

            foreach ($edges as $edge) {
                if (! empty($edge['node']['type'])) {
                    $definitions[] = $edge['node'];
                }
            }

            $cursor = ! empty($edges) ? end($edges)['cursor'] : null;
        } while (($response['body']['data']['metaobjectDefinitions']['pageInfo']['hasNextPage'] ?? false) && $cursor);

        return $definitions;
    }

    /**
     * @param  array<int, array<string, mixed>>  $fieldDefinitions
     * @param  array<string, string>  $typeByGid
     * @return array<int, array<string, mixed>>
     */
    private function reverseFields(array $fieldDefinitions, array $typeByGid): array
    {
        $fields = [];

        foreach ($fieldDefinitions as $definition) {
            $rawType = $definition['type']['name'] ?? '';

            if ($rawType === '') {
                continue;
            }

            $list = str_starts_with($rawType, 'list.');
            $type = $list ? substr($rawType, 5) : $rawType;
            $validations = $this->reverseValidations($definition['validations'] ?? []);

            $fields[] = array_filter([
                'key' => $definition['key'],
                'name' => $definition['name'] ?? $definition['key'],
                'type' => $type,
                'list' => $list,
                'required' => ! empty($definition['required']),
                'child' => $type === 'metaobject_reference' ? ($typeByGid[$validations['metaobject_definition_id'] ?? ''] ?? '') : '',
                'content_type' => $type === 'file_reference' ? ($validations['content_type'] ?? '') : '',
                'preset' => ! empty($validations['rules']['choices']) ? 'choice_list' : '',
                'validations' => $validations['rules'],
            ], fn ($value) => $value !== '' && $value !== null && $value !== []);
        }

        return $fields;
    }

    /**
     * @param  array<int, array{name: string, value: string}>  $validations
     * @return array{rules: array<string, mixed>, metaobject_definition_id?: string, content_type?: string}
     */
    private function reverseValidations(array $validations): array
    {
        $rules = [];
        $extra = [];

        foreach ($validations as $rule) {
            $name = $rule['name'] ?? '';
            $value = $rule['value'] ?? '';

            if ($name === 'min' || $name === 'max' || $name === 'scale_min' || $name === 'scale_max') {
                $bound = str_ends_with($name, 'min') ? 'min' : 'max';
                $decoded = json_decode((string) $value, true);

                if (is_array($decoded) && isset($decoded['value'])) {
                    $rules[$bound] = $decoded['value'];
                    $rules['unit'] = $decoded['unit'] ?? ($rules['unit'] ?? '');
                } else {
                    $rules[$bound] = $value;
                }
            } elseif ($name === 'max_precision' || $name === 'regex') {
                $rules[$name] = $value;
            } elseif ($name === 'choices') {
                $decoded = json_decode((string) $value, true);
                $rules['choices'] = is_array($decoded) ? implode(',', $decoded) : (string) $value;
            } elseif ($name === 'metaobject_definition_id') {
                $extra['metaobject_definition_id'] = $value;
            } elseif ($name === 'file_type_options') {
                $decoded = json_decode((string) $value, true);
                $extra['content_type'] = strtoupper((string) ($decoded[0] ?? ''));
            }
        }

        return ['rules' => $rules] + $extra;
    }

    /**
     * Order definitions so referenced (child) types come before the types that
     * reference them.
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function sortChildFirst(array $rows): array
    {
        $ordered = [];
        $visited = [];

        $visit = function (string $type) use (&$visit, &$ordered, &$visited, $rows) {
            if (isset($visited[$type]) || ! isset($rows[$type])) {
                return;
            }

            $visited[$type] = true;

            foreach ($rows[$type]['fields'] as $field) {
                if (($field['type'] ?? '') === 'metaobject_reference' && ! empty($field['child'])) {
                    $visit($field['child']);
                }
            }

            $ordered[] = $rows[$type];
        };

        foreach (array_keys($rows) as $type) {
            $visit($type);
        }

        return $ordered;
    }
}
