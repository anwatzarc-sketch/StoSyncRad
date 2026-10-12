<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Support\Enums\EnumLabelResolver;
use App\Support\Enums\SystemEnum;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * JSON shape for API responses (Chapter 11 §11.5): attributes as stored (enums as their codes,
 * decimals as strings, timestamps ISO-8601 UTC) plus `<attr>_label` for every enum so clients never
 * hard-code labels (labels are configurable). Hidden/sensitive attributes are never emitted.
 */
final class Presenter
{
    public function __construct(private readonly EnumLabelResolver $labels) {}

    /** @return array<string, mixed>|null */
    public function model(?Model $model, array $relations = []): ?array
    {
        if ($model === null) {
            return null;
        }
        $out = [];
        foreach ($model->attributesToArray() as $key => $_) {
            $out[$key] = $this->value($model->getAttribute($key), $key, $out);
        }
        foreach ($relations as $relation) {
            if ($model->relationLoaded($relation)) {
                $related = $model->getRelation($relation);
                $out[$relation] = $related instanceof Model ? $this->model($related)
                    : ($related === null ? null : $related->map(fn ($m) => $this->model($m))->values()->all());
            }
        }

        return $out;
    }

    /** @param  iterable<Model>  $models */
    public function collection(iterable $models, array $relations = []): array
    {
        $out = [];
        foreach ($models as $m) {
            $out[] = $this->model($m, $relations);
        }

        return $out;
    }

    /** Normalize plain arrays from services (models, enums, dates nested anywhere). */
    public function data(mixed $value): mixed
    {
        if ($value instanceof Model) {
            return $this->model($value);
        }
        if ($value instanceof BackedEnum) {
            return $value->value;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if (is_iterable($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->data($v);
                if ($v instanceof SystemEnum) {
                    $out[$k.'_label'] = $this->labels->label($v);
                }
            }

            return $out;
        }

        return $value;
    }

    private function value(mixed $v, string $key, array &$out): mixed
    {
        if ($v instanceof SystemEnum && $v instanceof BackedEnum) {
            $out[$key.'_label'] = $this->labels->label($v);

            return $v->value;
        }
        if ($v instanceof BackedEnum) {
            return $v->value;
        }
        if ($v instanceof DateTimeInterface) {
            return $v->format(DATE_ATOM);
        }
        if (is_array($v)) {
            return array_map(fn ($x) => $x instanceof BackedEnum ? $x->value : $x, $v);
        }

        return $v;
    }
}
