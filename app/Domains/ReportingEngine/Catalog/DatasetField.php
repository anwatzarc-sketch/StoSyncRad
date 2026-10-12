<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Catalog;

use App\Domains\ReportingEngine\Enums\ReportFieldType as T;
use App\Support\Enums\DataClassification as C;

/** One reportable column of a dataset view (Chapter 10 §10.2). */
final class DatasetField
{
    public function __construct(
        public readonly string $code,
        public readonly string $label,
        public readonly T $type,
        public readonly ?string $enumClass = null,
        public readonly C $classification = C::OPERATIONAL,
        public readonly ?string $permission = null,
        public readonly bool $aggregatable = false,
        public readonly bool $groupable = true,
        public readonly bool $filterable = true,
        public readonly bool $sortable = true,
        public readonly ?string $drill = null,
    ) {}

    public static function uuid(string $code, string $label, ?string $drill = null): self
    {
        return new self($code, $label, T::UUID, groupable: false, drill: $drill);
    }

    public static function string(string $code, string $label, ?string $drill = null): self
    {
        return new self($code, $label, T::STRING, drill: $drill);
    }

    /** @param  class-string  $enumClass */
    public static function enum(string $code, string $label, string $enumClass): self
    {
        return new self($code, $label, T::ENUM, $enumClass);
    }

    public static function int(string $code, string $label): self
    {
        return new self($code, $label, T::INTEGER, aggregatable: true);
    }

    public static function qty(string $code, string $label): self
    {
        return new self($code, $label, T::QUANTITY, aggregatable: true, groupable: false);
    }

    public static function decimal(string $code, string $label): self
    {
        return new self($code, $label, T::DECIMAL, aggregatable: true, groupable: false);
    }

    /** Monetary value; cost-sensitive unless stated otherwise. */
    public static function money(string $code, string $label, C $classification = C::COST_SENSITIVE): self
    {
        return new self($code, $label, T::MONEY, classification: $classification, aggregatable: true, groupable: false);
    }

    public static function date(string $code, string $label): self
    {
        return new self($code, $label, T::DATE);
    }

    public static function datetime(string $code, string $label): self
    {
        return new self($code, $label, T::DATETIME);
    }

    public static function bool(string $code, string $label): self
    {
        return new self($code, $label, T::BOOLEAN);
    }

    public static function personal(string $code, string $label): self
    {
        return new self($code, $label, T::STRING, classification: C::PERSONAL_DATA);
    }

    /** Permission required to select, filter, sort or aggregate this field. */
    public function requiredPermission(): ?string
    {
        return $this->permission ?? match ($this->classification) {
            C::COST_SENSITIVE => 'reporting.cost_fields.view',
            C::PERSONAL_DATA => 'reporting.personal_fields.view',
            C::RESTRICTED => 'reporting.restricted_fields.view',
            default => null,
        };
    }

    public function isNumeric(): bool
    {
        return in_array($this->type, [T::INTEGER, T::DECIMAL, T::MONEY, T::QUANTITY], true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code, 'label' => $this->label, 'type' => $this->type->value,
            'enum' => $this->enumClass !== null ? $this->enumClass::enumCode() : null,
            'classification' => $this->classification->value, 'required_permission' => $this->requiredPermission(),
            'aggregatable' => $this->aggregatable, 'groupable' => $this->groupable, 'filterable' => $this->filterable,
            'sortable' => $this->sortable, 'drill' => $this->drill,
        ];
    }
}
