<?php

declare(strict_types=1);

namespace App\Domains\Platform\Services;

use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Organization\Enums\SequenceResetPolicy;
use App\Domains\Organization\Enums\SequenceScope;
use App\Support\Sentinel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Allocates human-readable document numbers (Chapter 08 §8.13).
 *
 * Allocation runs in its own short transaction on the numbering connection so that postings
 * never hold the sequence-row lock until commit (which would serialize every posting of a type).
 * Consequence: a rolled-back posting leaves a gap — explicitly acceptable per §8.13. Duplicates
 * remain impossible: each document table has a unique index on document_number.
 *
 * Pattern tokens: {PREFIX} {YYYY} {YY} {MM} {BRANCH} {WH} {SEQ:n}
 */
final class DocumentNumberAllocator
{
    /**
     * @param  array{branch_id?: ?string, branch_code?: ?string, warehouse_id?: ?string, warehouse_code?: ?string}  $context
     */
    public function next(DocumentType $type, array $context = [], ?CarbonImmutable $date = null): string
    {
        $date ??= now()->toImmutable();
        $connection = config('platform.numbering_connection');
        $db = $connection ? DB::connection($connection) : DB::connection();

        return $db->transaction(function () use ($db, $type, $context, $date): string {
            $template = $db->table('document_sequences')
                ->where('document_type', $type->value)->where('period_key', '')
                ->orderByRaw("CASE scope WHEN 'global' THEN 1 ELSE 0 END")
                ->first();

            $scope = $template !== null ? SequenceScope::from($template->scope) : SequenceScope::GLOBAL;
            $reset = $template !== null ? SequenceResetPolicy::from($template->reset_policy) : SequenceResetPolicy::YEARLY;
            $prefix = $template->prefix ?? $type->defaultPrefix();
            $pattern = $template->pattern ?? '{PREFIX}-{YYYY}-{SEQ:6}';
            $padding = (int) ($template->sequence_padding ?? 6);

            $scopeId = Sentinel::orId(match ($scope) {
                SequenceScope::PER_BRANCH => $context['branch_id'] ?? null,
                SequenceScope::PER_WAREHOUSE => $context['warehouse_id'] ?? null,
                default => null,
            });
            $periodKey = match ($scope === SequenceScope::PER_YEAR ? SequenceResetPolicy::YEARLY : $reset) {
                SequenceResetPolicy::YEARLY => $date->format('Y'),
                SequenceResetPolicy::MONTHLY => $date->format('Y-m'),
                SequenceResetPolicy::NEVER => '*',
            };

            // Upsert the concrete counter row, then increment it atomically.
            $db->statement(
                'INSERT INTO document_sequences (id, document_type, scope, scope_id, period_key, prefix, pattern, reset_policy, next_number, sequence_padding, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, now(), now())
                 ON CONFLICT (document_type, scope, scope_id, period_key) DO NOTHING',
                [(string) Str::uuid7(), $type->value, $scope->value, $scopeId, $periodKey, $prefix, $pattern, $reset->value,
                    (int) ($template->next_number ?? 1), $padding]
            );
            $row = $db->selectOne(
                'UPDATE document_sequences SET next_number = next_number + 1, updated_at = now()
                  WHERE document_type = ? AND scope = ? AND period_key = ? AND scope_id = ?
              RETURNING next_number - 1 AS allocated',
                [$type->value, $scope->value, $periodKey, $scopeId]
            );

            return $this->format($pattern, $prefix, (int) $row->allocated, $padding, $date, $context);
        });
    }

    /** @param  array<string, ?string>  $context */
    public function format(string $pattern, string $prefix, int $number, int $padding, CarbonImmutable $date, array $context = []): string
    {
        $out = preg_replace_callback('/\{SEQ(?::(\d+))?\}/', static fn (array $m) => str_pad((string) $number, (int) ($m[1] ?? $padding), '0', STR_PAD_LEFT), $pattern) ?? $pattern;

        return strtr($out, [
            '{PREFIX}' => $prefix,
            '{YYYY}' => $date->format('Y'),
            '{YY}' => $date->format('y'),
            '{MM}' => $date->format('m'),
            '{BRANCH}' => (string) ($context['branch_code'] ?? ''),
            '{WH}' => (string) ($context['warehouse_code'] ?? ''),
        ]);
    }
}
