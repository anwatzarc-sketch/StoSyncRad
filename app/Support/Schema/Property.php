<?php

declare(strict_types=1);

namespace App\Support\Schema;

use App\Support\Enums\SystemEnum;

/**
 * Declarative description of one table property (Chapter 05 §5.3).
 *
 * The same declaration drives validation, mass-assignment, OpenAPI, TypeScript types and the
 * reporting catalog, so it must stay the single source of truth for the property's rules.
 */
final class Property
{
    public ?bool $nullable = null;

    public mixed $default = null;

    public bool $hasDefault = false;

    /** 'create' | 'update' | 'always' | null */
    public ?string $requiredOn = null;

    public ?int $maxLength = null;

    public ?int $precision = null;

    public ?int $scale = null;

    public ?string $min = null;

    public ?string $max = null;

    public ?string $pattern = null;

    /** @var class-string<SystemEnum>|null */
    public ?string $enumClass = null;

    /** @var class-string<\Illuminate\Database\Eloquent\Model>|null */
    public ?string $references = null;

    public ?string $referenceStatusColumn = null;

    /** @var array<int, string> */
    public array $referenceActiveValues = [];

    public ?string $writableBy = null;

    /** @var array<int, string> document states after which the property is immutable */
    public array $immutableAfter = [];

    public bool $serverControlled = false;

    public bool $sensitive = false;

    public bool $reportable = true;

    public ?string $classification = null;

    public bool $searchable = false;

    public bool $sortable = false;

    public bool $filterable = false;

    public ?string $label = null;

    public ?string $help = null;

    public ?string $after = null;

    public ?string $before = null;

    /** @var class-string<TableSchema>|null  schema of each element for ARRAY properties */
    public ?string $items = null;

    /** @var Property|null  scalar element definition for ARRAY properties */
    public ?Property $itemProperty = null;

    public bool $trim = false;

    public bool $unique = false;

    /** Accepted from clients but not a table column (nested lines, tag ids, passwords, versions). */
    public bool $virtual = false;

    private function __construct(public readonly PropertyType $type) {}

    // ---- Constructors --------------------------------------------------------------------

    public static function uuid(): self
    {
        return new self(PropertyType::UUID);
    }

    public static function string(int $max = 255): self
    {
        $p = new self(PropertyType::STRING);
        $p->maxLength = $max;

        return $p;
    }

    public static function text(?int $max = null): self
    {
        $p = new self(PropertyType::TEXT);
        $p->maxLength = $max;

        return $p;
    }

    public static function integer(): self
    {
        return new self(PropertyType::INTEGER);
    }

    public static function decimal(int $precision = 20, int $scale = 6): self
    {
        $p = new self(PropertyType::DECIMAL);
        $p->precision = $precision;
        $p->scale = $scale;

        return $p;
    }

    public static function quantity(): self
    {
        $p = new self(PropertyType::QUANTITY);
        $p->precision = 20;
        $p->scale = 6;

        return $p;
    }

    public static function money(): self
    {
        $p = new self(PropertyType::MONEY);
        $p->precision = 20;
        $p->scale = 4;

        return $p;
    }

    public static function unitCost(): self
    {
        $p = new self(PropertyType::MONEY);
        $p->precision = 20;
        $p->scale = 8;

        return $p;
    }

    public static function percentage(): self
    {
        $p = new self(PropertyType::PERCENTAGE);
        $p->precision = 9;
        $p->scale = 6;
        $p->min = '0';
        $p->max = '100';

        return $p;
    }

    public static function boolean(): self
    {
        return new self(PropertyType::BOOLEAN);
    }

    public static function date(): self
    {
        return new self(PropertyType::DATE);
    }

    public static function datetime(): self
    {
        return new self(PropertyType::DATETIME);
    }

    public static function time(): self
    {
        return new self(PropertyType::TIME);
    }

    /** @param  class-string<SystemEnum>  $enumClass */
    public static function enum(string $enumClass): self
    {
        $p = new self(PropertyType::ENUM);
        $p->enumClass = $enumClass;
        $p->maxLength = 64;

        return $p;
    }

    public static function json(): self
    {
        return new self(PropertyType::JSON);
    }

    public static function email(): self
    {
        $p = new self(PropertyType::EMAIL);
        $p->maxLength = 254;

        return $p;
    }

    public static function phone(): self
    {
        $p = new self(PropertyType::PHONE);
        $p->maxLength = 32;

        return $p;
    }

    public static function url(): self
    {
        $p = new self(PropertyType::URL);
        $p->maxLength = 2048;

        return $p;
    }

    public static function barcode(): self
    {
        $p = new self(PropertyType::BARCODE);
        $p->maxLength = 128;

        return $p;
    }

    /** Any JSON value; only for values validated downstream by their own registry (configuration). */
    public static function any(): self
    {
        return new self(PropertyType::ANY);
    }

    public static function fileRef(): self
    {
        return new self(PropertyType::FILE_REF);
    }

    /** Array of nested schemas (document lines) or scalar elements. */
    public static function arrayOf(string|Property $of): self
    {
        $p = new self(PropertyType::ARRAY);
        if ($of instanceof Property) {
            $p->itemProperty = $of;
        } else {
            $p->items = $of;
        }

        return $p;
    }

    // ---- Fluent modifiers ------------------------------------------------------------------

    public function nullable(bool $nullable = true): self
    {
        $this->nullable = $nullable;

        return $this;
    }

    public function default(mixed $value): self
    {
        $this->default = $value;
        $this->hasDefault = true;

        return $this;
    }

    public function required(string $on = 'always'): self
    {
        $this->requiredOn = $on;

        return $this;
    }

    public function requiredOnCreate(): self
    {
        return $this->required('create');
    }

    public function min(string|int|float $min): self
    {
        $this->min = (string) $min;

        return $this;
    }

    public function max(string|int|float $max): self
    {
        $this->max = (string) $max;

        return $this;
    }

    public function positive(): self
    {
        return $this->min('0.000001');
    }

    public function nonNegative(): self
    {
        return $this->min('0');
    }

    public function pattern(string $regex): self
    {
        $this->pattern = $regex;

        return $this;
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     * @param  array<int, string>  $activeValues
     */
    public function references(string $model, ?string $statusColumn = null, array $activeValues = []): self
    {
        $this->references = $model;
        $this->referenceStatusColumn = $statusColumn;
        $this->referenceActiveValues = $activeValues;

        return $this;
    }

    public function writableBy(string $permission): self
    {
        $this->writableBy = $permission;

        return $this;
    }

    /** @param  string  ...$states */
    public function immutableAfter(string ...$states): self
    {
        $this->immutableAfter = $states;

        return $this;
    }

    public function serverControlled(): self
    {
        $this->serverControlled = true;

        return $this;
    }

    public function sensitive(): self
    {
        $this->sensitive = true;
        $this->reportable = false;

        return $this;
    }

    public function notReportable(): self
    {
        $this->reportable = false;

        return $this;
    }

    public function classification(string $classification): self
    {
        $this->classification = $classification;

        return $this;
    }

    public function searchable(): self
    {
        $this->searchable = true;

        return $this;
    }

    public function sortable(): self
    {
        $this->sortable = true;

        return $this;
    }

    public function filterable(): self
    {
        $this->filterable = true;

        return $this;
    }

    public function label(string $label, ?string $help = null): self
    {
        $this->label = $label;
        $this->help = $help;

        return $this;
    }

    public function after(string $otherProperty): self
    {
        $this->after = $otherProperty;

        return $this;
    }

    public function before(string $otherProperty): self
    {
        $this->before = $otherProperty;

        return $this;
    }

    public function trim(): self
    {
        $this->trim = true;

        return $this;
    }

    public function unique(): self
    {
        $this->unique = true;

        return $this;
    }

    public function virtual(): self
    {
        $this->virtual = true;
        $this->reportable = false;

        return $this;
    }

    // ---- Queries ---------------------------------------------------------------------------

    public function isNullable(): bool
    {
        return $this->nullable === true;
    }

    public function isRequiredFor(string $operation): bool
    {
        return $this->requiredOn === 'always' || $this->requiredOn === $operation;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type->value,
            'nullable' => $this->nullable,
            'default' => $this->hasDefault ? $this->default : null,
            'required_on' => $this->requiredOn,
            'max_length' => $this->maxLength,
            'precision' => $this->precision,
            'scale' => $this->scale,
            'min' => $this->min,
            'max' => $this->max,
            'pattern' => $this->pattern,
            'enum' => $this->enumClass ? $this->enumClass::enumCode() : null,
            'enum_values' => $this->enumClass ? $this->enumClass::values() : null,
            'references' => $this->references ? class_basename($this->references) : null,
            'writable_by' => $this->writableBy,
            'immutable_after' => $this->immutableAfter ?: null,
            'server_controlled' => $this->serverControlled ?: null,
            'sensitive' => $this->sensitive ?: null,
            'reportable' => $this->reportable,
            'classification' => $this->classification,
            'searchable' => $this->searchable ?: null,
            'sortable' => $this->sortable ?: null,
            'filterable' => $this->filterable ?: null,
            'label' => $this->label,
            'help' => $this->help,
            'after' => $this->after,
            'before' => $this->before,
            'items' => $this->items ? $this->items::name() : null,
            'item_type' => $this->itemProperty?->type->value,
            'item_enum' => $this->itemProperty?->enumClass ? $this->itemProperty->enumClass::enumCode() : null,
            'item_enum_values' => $this->itemProperty?->enumClass ? $this->itemProperty->enumClass::values() : null,
            'unique' => $this->unique ?: null,
            'virtual' => $this->virtual ?: null,
        ], static fn ($v) => $v !== null);
    }
}
