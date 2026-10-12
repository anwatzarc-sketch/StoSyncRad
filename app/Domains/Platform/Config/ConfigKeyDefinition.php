<?php

declare(strict_types=1);

namespace App\Domains\Platform\Config;

use App\Support\Enums\SystemEnum;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Support\Str;

/**
 * Declaration of one configuration key (Chapter 03 §3.2, Appendix C).
 */
final class ConfigKeyDefinition
{
    /**
     * @param  array<int, ConfigScopeType>  $scopes
     * @param  class-string<SystemEnum>|null  $enumClass
     * @param  array<string, mixed>  $constraints  min, max, min_items, max_items, pattern, items_enum, choices
     */
    public function __construct(
        public readonly string $key,
        public readonly ConfigValueType $type,
        public readonly mixed $default,
        public readonly string $label,
        public readonly string $help,
        public readonly array $scopes = [ConfigScopeType::GLOBAL],
        public readonly ?string $enumClass = null,
        public readonly array $constraints = [],
        public readonly bool $sensitive = false,
        public readonly bool $restartRequired = false,
        public readonly ?string $permission = null,
        public readonly string $sinceVersion = '2.0.0',
    ) {}

    public function group(): string
    {
        return Str::before($this->key, '.');
    }

    /** Copy whose changes require a specific permission instead of `platform.config.<group>_manage`. */
    public function managedBy(string $permission): self
    {
        return new self(...array_merge(get_object_vars($this), ['permission' => $permission]));
    }

    public function managePermission(): string
    {
        return $this->permission ?? 'platform.config.'.$this->group().'_manage';
    }

    public function allowsScope(ConfigScopeType $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    /**
     * Validate and normalize a candidate value for this key.
     *
     * @throws DomainException CONFIG_VALUE_INVALID
     */
    public function normalize(mixed $value): mixed
    {
        $fail = fn (string $why) => throw DomainException::of(ErrorCode::CONFIG_VALUE_INVALID,
            "Invalid value for `{$this->key}`: {$why}.", ['key' => $this->key, 'type' => $this->type->value] + $this->constraintsForError());

        $c = $this->constraints;

        // Forms send '' for an empty field and Laravel's ConvertEmptyStringsToNull turns it into null:
        // for text keys that means "empty"; for keys without a default it means "not set".
        // Secrets are excluded on purpose — an empty secret never silently replaces a stored one.
        if ($value === null) {
            if (in_array($this->type, [ConfigValueType::STRING, ConfigValueType::ASSET_REF], true)) {
                $value = '';
            } elseif ($this->default === null && $this->type !== ConfigValueType::SECRET) {
                return null;
            }
        }

        switch ($this->type) {
            case ConfigValueType::STRING:
            case ConfigValueType::SECRET:
            case ConfigValueType::ASSET_REF:
                if (! is_string($value)) {
                    $fail('expected a string');
                }
                if (isset($c['max_length']) && mb_strlen($value) > $c['max_length']) {
                    $fail("must not exceed {$c['max_length']} characters");
                }
                if (isset($c['pattern']) && preg_match($c['pattern'], $value) !== 1) {
                    $fail('does not match the required format');
                }
                if (isset($c['choices']) && ! in_array($value, $c['choices'], true)) {
                    $fail('must be one of: '.implode(', ', $c['choices']));
                }

                return $value;

            case ConfigValueType::UUID:
                if (! is_string($value) || ! Str::isUuid($value)) {
                    $fail('expected a UUID');
                }

                return strtolower($value);

            case ConfigValueType::TIME:
                if (! is_string($value) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) !== 1) {
                    $fail('expected HH:MM');
                }

                return $value;

            case ConfigValueType::INTEGER:
            case ConfigValueType::DURATION:
                if (is_bool($value) || ! (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value)))) {
                    $fail('expected an integer');
                }
                $int = (int) $value;
                if (isset($c['min']) && $int < $c['min']) {
                    $fail("must be at least {$c['min']}");
                }
                if (isset($c['max']) && $int > $c['max']) {
                    $fail("must be at most {$c['max']}");
                }

                return $int;

            case ConfigValueType::DECIMAL:
            case ConfigValueType::PERCENTAGE:
            case ConfigValueType::MONEY:
                if (is_bool($value) || ! is_numeric($value)) {
                    $fail('expected a decimal number');
                }
                $str = is_string($value) ? trim($value) : (string) $value;
                if (isset($c['min']) && bccomp($str, (string) $c['min'], 10) < 0) {
                    $fail("must be at least {$c['min']}");
                }
                if (isset($c['max']) && bccomp($str, (string) $c['max'], 10) > 0) {
                    $fail("must be at most {$c['max']}");
                }
                if ($this->type === ConfigValueType::PERCENTAGE && (bccomp($str, '0', 10) < 0 || bccomp($str, '100', 10) > 0)) {
                    $fail('must be between 0 and 100');
                }

                return $str;

            case ConfigValueType::BOOLEAN:
                if (is_bool($value)) {
                    return $value;
                }
                if (in_array($value, [1, 0, '1', '0', 'true', 'false'], true)) {
                    return in_array($value, [1, '1', 'true'], true);
                }
                $fail('expected true or false');
                // no break
            case ConfigValueType::ENUM:
                $enum = $this->enumClass ?? $fail('enum class missing');
                $case = $enum::tryFromInput($value);
                if ($case === null) {
                    $fail('must be one of: '.implode(', ', $enum::values()));
                }

                return $case->value;

            case ConfigValueType::LIST:
                if (! is_array($value) || ! array_is_list($value)) {
                    $fail('expected a list');
                }
                if (isset($c['min_items']) && count($value) < $c['min_items']) {
                    $fail("must contain at least {$c['min_items']} items");
                }
                if (isset($c['max_items']) && count($value) > $c['max_items']) {
                    $fail("must contain at most {$c['max_items']} items");
                }
                $items = [];
                foreach ($value as $item) {
                    if (isset($c['items_enum'])) {
                        $case = $c['items_enum']::tryFromInput($item);
                        if ($case === null) {
                            $fail('items must be one of: '.implode(', ', $c['items_enum']::values()));
                        }
                        $items[] = $case->value;
                    } elseif (($c['items_type'] ?? 'string') === 'integer') {
                        if (! is_int($item) && ! (is_string($item) && preg_match('/^\d+$/', $item))) {
                            $fail('items must be integers');
                        }
                        $items[] = (int) $item;
                    } else {
                        if (! is_string($item)) {
                            $fail('items must be strings');
                        }
                        $items[] = $item;
                    }
                }

                return array_values(array_unique($items, SORT_REGULAR));

            case ConfigValueType::JSON:
                if (! is_array($value)) {
                    $fail('expected an object');
                }

                return $value;
        }

        return $value;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'group' => $this->group(),
            'type' => $this->type->value,
            'default' => $this->sensitive ? null : $this->default,
            'label' => $this->label,
            'help' => $this->help,
            'scopes' => array_map(static fn (ConfigScopeType $s) => $s->value, $this->scopes),
            'enum' => $this->enumClass ? $this->enumClass::enumCode() : null,
            'enum_values' => $this->enumClass ? $this->enumClass::values() : null,
            'constraints' => $this->constraintsForError(),
            'sensitive' => $this->sensitive,
            'restart_required' => $this->restartRequired,
            'permission' => $this->managePermission(),
            'since_version' => $this->sinceVersion,
        ];
    }

    /** @return array<string, mixed> */
    private function constraintsForError(): array
    {
        $c = $this->constraints;
        if (isset($c['items_enum'])) {
            $c['items_enum_code'] = $c['items_enum']::enumCode();
            $c['items_enum'] = $c['items_enum']::values();
        }

        return $c;
    }
}
