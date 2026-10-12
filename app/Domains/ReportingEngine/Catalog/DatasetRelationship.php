<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Catalog;

use App\Domains\ReportingEngine\Enums\ReportRelationshipCardinality;

/** An allowed join from one dataset to another (Chapter 10 §10.2). */
final class DatasetRelationship
{
    /** @param  array<int, array{0: string, 1: string}>  $keys  [local column, target column] pairs */
    public function __construct(
        public readonly string $code,
        public readonly string $label,
        public readonly string $target,
        public readonly array $keys,
        public readonly ReportRelationshipCardinality $cardinality = ReportRelationshipCardinality::MANY_TO_ONE,
    ) {}

    public static function to(string $code, string $label, string $target, string $local, string $remote = 'id'): self
    {
        return new self($code, $label, $target, [[$local, $remote]]);
    }

    public static function many(string $code, string $label, string $target, string $remote, string $local = 'id'): self
    {
        return new self($code, $label, $target, [[$local, $remote]], ReportRelationshipCardinality::ONE_TO_MANY);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['code' => $this->code, 'label' => $this->label, 'target' => $this->target, 'keys' => $this->keys, 'cardinality' => $this->cardinality->value];
    }
}
