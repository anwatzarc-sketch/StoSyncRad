<?php

declare(strict_types=1);

namespace App\Domains\Platform\Setup;

use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Platform\Config\ConfigRegistry;
use App\Domains\Platform\Config\PlatformConfig;
use App\Domains\Platform\Config\SetConfigurationValueAction;
use App\Support\Exceptions\DomainException;
use App\Support\Validation\ValidationErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Applies a {key: value} map of configuration from a setup step or a config bundle, restricted to
 * the groups that step owns. Every key is validated by its declaration; all errors are reported
 * together and nothing is applied when any key is invalid.
 */
final class ConfigApplier
{
    public function __construct(
        private readonly ConfigRegistry $registry,
        private readonly SetConfigurationValueAction $set,
        private readonly PlatformConfig $config,
    ) {}

    /**
     * @param  array<string, mixed>  $values
     * @param  array<int, string>  $allowedPrefixes  e.g. ['locale.', 'feature.']
     * @return array<string, mixed> applied values
     */
    public function apply(array $values, array $allowedPrefixes, ?UserAccount $actor, ?string $reason = null): array
    {
        $errors = [];
        $normalized = [];
        foreach ($values as $key => $value) {
            $path = 'config.'.$key;
            if (! Str::startsWith((string) $key, $allowedPrefixes)) {
                $errors[$path][] = ValidationErrorCode::UNKNOWN_PROPERTY->message($path, [], "`$key` cannot be set in this step.");

                continue;
            }
            if (! $this->registry->has((string) $key)) {
                $errors[$path][] = ValidationErrorCode::UNKNOWN_PROPERTY->message($path, [], "`$key` is not a declared configuration key.");

                continue;
            }
            // An empty secret (masked or never entered) means "keep the stored one".
            if ($this->registry->get((string) $key)->sensitive && ($value === null || $value === '' || $value === '***')) {
                continue;
            }
            try {
                $normalized[$key] = $this->registry->get((string) $key)->normalize($value);
            } catch (DomainException $e) {
                $errors[$path][] = ValidationErrorCode::CUSTOM->message($path, $e->details, $e->getMessage());
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($normalized, $actor, $reason) {
            foreach ($normalized as $key => $value) {
                if ($this->config->get($key) === $value && ! $this->registry->get($key)->sensitive) {
                    continue; // unchanged — keep history clean on re-submission
                }
                $this->set->execute($key, $value, $actor, reason: $reason ?? 'Platform Setup');
            }
        });

        return $normalized;
    }

    /**
     * Current values of every key under the given prefixes (secrets masked).
     *
     * @param  array<int, string>  $prefixes
     * @return array<string, mixed>
     */
    public function current(array $prefixes): array
    {
        $out = [];
        foreach ($this->registry->all() as $key => $definition) {
            if (Str::startsWith($key, $prefixes)) {
                $out[$key] = $definition->sensitive ? ($this->config->get($key) ? '***' : '') : $this->config->get($key);
            }
        }

        return $out;
    }

    /**
     * Declarations for the wizard form.
     *
     * @param  array<int, string>  $prefixes
     * @return array<int, array<string, mixed>>
     */
    public function definitions(array $prefixes): array
    {
        return $this->registry->all()->filter(fn ($d, $key) => Str::startsWith($key, $prefixes))
            ->map(fn ($d) => $d->toArray())->values()->all();
    }
}
