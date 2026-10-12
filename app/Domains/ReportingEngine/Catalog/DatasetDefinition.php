<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Catalog;

use App\Support\Enums\DataClassification;

/**
 * A reportable dataset: a `reporting.<code>` view (reportable columns only, labels joined, scope
 * columns exposed, sensitive data excluded) plus its field and relationship metadata.
 */
final class DatasetDefinition
{
    /** @var array<string, DatasetField> */
    public readonly array $fields;

    /** @var array<string, DatasetRelationship> */
    public readonly array $relationships;

    /**
     * @param  array<int, DatasetField>  $fields
     * @param  array<int, DatasetRelationship>  $relationships
     * @param  array<int, string>  $warehouseScope  view columns holding warehouse ids (OR-ed)
     * @param  array<int, array{0: string, 1: string}>  $defaultSort
     */
    public function __construct(
        public readonly string $code,
        public readonly string $label,
        public readonly string $module,
        public readonly string $sql,
        array $fields,
        public readonly string $permission,
        public readonly array $warehouseScope = [],
        public readonly ?string $branchScope = null,
        public readonly ?string $ownColumn = null,
        public readonly DataClassification $classification = DataClassification::OPERATIONAL,
        public readonly ?string $feature = null,
        array $relationships = [],
        public readonly array $defaultSort = [],
        public readonly string $description = '',
        public readonly string $primaryKey = 'id',
    ) {
        $f = [];
        foreach ($fields as $field) {
            $f[$field->code] = $field;
        }
        $this->fields = $f;
        $r = [];
        foreach ($relationships as $rel) {
            $r[$rel->code] = $rel;
        }
        $this->relationships = $r;
    }

    public function view(): string
    {
        return 'reporting.'.$this->code;
    }

    public function hash(): string
    {
        return hash('sha256', json_encode([$this->sql, array_map(fn ($f) => $f->toArray(), $this->fields),
            array_map(fn ($r) => $r->toArray(), $this->relationships), $this->warehouseScope, $this->branchScope, $this->permission], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code, 'label' => $this->label, 'description' => $this->description, 'module' => $this->module,
            'classification' => $this->classification->value, 'required_permission' => $this->permission, 'feature_flag' => $this->feature,
            'primary_key' => $this->primaryKey, 'warehouse_scope' => $this->warehouseScope, 'branch_scope' => $this->branchScope,
            'default_sort' => $this->defaultSort, 'fields' => array_values(array_map(fn ($f) => $f->toArray(), $this->fields)),
            'relationships' => array_values(array_map(fn ($r) => $r->toArray(), $this->relationships)),
        ];
    }
}
