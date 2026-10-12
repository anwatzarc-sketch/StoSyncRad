<?php

declare(strict_types=1);

namespace App\Domains\Platform\Seeds;

/**
 * A versioned, idempotent seed (Chapter 02 §2.6). Seeds insert missing rows by natural key and
 * never overwrite client-edited rows; re-running a seed is a no-op.
 */
abstract class Seed
{
    abstract public function name(): string;

    abstract public function version(): string;

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, int> counters for the seed log, e.g. ['inserted' => 12, 'existing' => 40]
     */
    abstract public function run(array $options = []): array;

    /** Checksum of the seed definition (class source + version) for the seed_runs log. */
    public function checksum(): string
    {
        $file = (new \ReflectionClass($this))->getFileName();

        return hash('sha256', $this->version().'|'.($file !== false ? (string) file_get_contents($file) : static::class));
    }
}
