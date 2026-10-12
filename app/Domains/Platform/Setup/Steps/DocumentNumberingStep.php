<?php

declare(strict_types=1);

namespace App\Domains\Platform\Setup\Steps;

use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Organization\Enums\SequenceResetPolicy;
use App\Domains\Organization\Enums\SequenceScope;
use App\Domains\Platform\Enums\PlatformSetupStep;
use App\Domains\Platform\Seeds\DocumentSequencesSeed;
use App\Domains\Platform\Seeds\SeedRunner;
use App\Domains\Platform\Services\DocumentNumberAllocator;
use App\Domains\Platform\Setup\SetupContext;
use App\Domains\Platform\Setup\SetupStepHandler;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Sentinel;
use Illuminate\Support\Facades\DB;

/**
 * Step 10 — document numbering templates for every document type (Chapter 08 §8.13).
 * Payload: {sequences: {document_type: {prefix, pattern, scope, reset_policy, starting_number}}}
 */
final class DocumentNumberingStep implements SetupStepHandler
{
    public function __construct(private readonly SeedRunner $seeds, private readonly DocumentNumberAllocator $numbers, private readonly AuditRecorder $audit) {}

    public function step(): PlatformSetupStep
    {
        return PlatformSetupStep::DOCUMENT_NUMBERING;
    }

    public function handle(array $payload, SetupContext $context): array
    {
        $this->seeds->run(DocumentSequencesSeed::class);
        $updated = 0;
        foreach ((array) ($payload['sequences'] ?? []) as $typeValue => $settings) {
            $type = DocumentType::tryFrom((string) $typeValue) ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED,
                "Unknown document type `$typeValue`.", ['allowed_values' => DocumentType::values()]);
            $prefix = (string) ($settings['prefix'] ?? $type->defaultPrefix());
            $pattern = (string) ($settings['pattern'] ?? '{PREFIX}-{YYYY}-{SEQ:6}');
            if (preg_match('/^[A-Z0-9]{1,10}$/', $prefix) !== 1) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, "Prefix for `$typeValue` must be 1–10 uppercase letters/digits.");
            }
            if (! str_contains($pattern, '{SEQ') || strlen($pattern) > 60 || preg_match('/^[A-Za-z0-9{}:\-\/_.]+$/', $pattern) !== 1) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, "Pattern for `$typeValue` must contain {SEQ} and only safe characters.");
            }
            $scope = SequenceScope::tryFrom((string) ($settings['scope'] ?? 'global')) ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED,
                'Invalid sequence scope.', ['allowed_values' => SequenceScope::values()]);
            $reset = SequenceResetPolicy::tryFrom((string) ($settings['reset_policy'] ?? 'yearly')) ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED,
                'Invalid reset policy.', ['allowed_values' => SequenceResetPolicy::values()]);
            $start = max(1, (int) ($settings['starting_number'] ?? 1));

            $updated += DB::table('document_sequences')->where('document_type', $type->value)->where('period_key', '')->where('scope_id', Sentinel::UUID)
                ->update(['prefix' => $prefix, 'pattern' => $pattern, 'scope' => $scope->value, 'reset_policy' => $reset->value, 'next_number' => $start, 'updated_at' => now()]);
        }
        $this->audit->record($context->actor, 'platform.numbering.updated', 'document_sequences', null, null, ['updated' => $updated]);

        return ['updated' => $updated, 'preview' => $this->preview()];
    }

    /** @return array<string, string> */
    public function preview(): array
    {
        $out = [];
        foreach (DB::table('document_sequences')->where('period_key', '')->get() as $row) {
            $out[$row->document_type] = $this->numbers->format($row->pattern, $row->prefix, (int) $row->next_number, (int) $row->sequence_padding,
                now()->toImmutable(), ['branch_code' => 'HQ', 'warehouse_code' => 'WH1']);
        }

        return $out;
    }

    public function current(): array
    {
        return ['sequences' => DB::table('document_sequences')->where('period_key', '')->orderBy('document_type')->get()->toArray(), 'preview' => $this->preview()];
    }
}
