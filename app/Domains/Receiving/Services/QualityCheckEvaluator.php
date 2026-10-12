<?php

declare(strict_types=1);

namespace App\Domains\Receiving\Services;

use App\Domains\Catalog\Enums\QcCheckType;
use App\Domains\Catalog\Models\Item;
use App\Domains\Catalog\Models\QcChecklistTemplate;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Inventory\Enums\QcOutcome;
use App\Domains\Inventory\Models\QualityCheck;
use App\Domains\Organization\Enums\DocumentType;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;

/**
 * Evaluates checklist answers against the item's QC template (Chapter 07 §7.7):
 * a required check without an answer is invalid; an answer in fail_values or outside min/max fails
 * the check. Any failure → fail (or conditional when a partial quantity passed).
 */
final class QualityCheckEvaluator
{
    /** @param  array<string, mixed>  $answers */
    public function record(DocumentType $type, string $documentId, ?string $lineId, Item $item, string $warehouseId, ?string $lotId,
        array $answers, string $inspected, string $failed, ?string $notes, UserAccount $actor): QualityCheck
    {
        $template = $item->qc_checklist_template_id ? QcChecklistTemplate::query()->find($item->qc_checklist_template_id) : null;
        $template ??= QcChecklistTemplate::query()->where('code', 'visual_inspection')->where('is_active', true)->first();
        $results = [];
        $failures = 0;
        foreach ((array) ($template?->checks ?? []) as $check) {
            $code = (string) $check['code'];
            $answer = $answers[$code] ?? null;
            if ($answer === null || $answer === '') {
                if (($check['required'] ?? true) === true) {
                    throw DomainException::of(ErrorCode::VALIDATION_FAILED, "Answer the check `{$check['label']}`.", ['field' => "answers.$code"]);
                }

                continue;
            }
            $type_ = QcCheckType::tryFrom((string) ($check['type'] ?? 'text')) ?? QcCheckType::TEXT;
            $failed_ = $this->fails($type_, $answer, $check);
            $failures += $failed_ ? 1 : 0;
            $results[] = ['code' => $code, 'label' => $check['label'], 'answer' => $answer, 'passed' => ! $failed_];
        }
        if (D::gt($failed, $inspected)) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Failed quantity cannot exceed the inspected quantity.', ['field' => 'failed_quantity']);
        }
        $outcome = $failures === 0 && ! D::isPositive($failed) ? QcOutcome::PASS
            : (D::lt($failed, $inspected) && D::isPositive($failed) ? QcOutcome::CONDITIONAL : QcOutcome::FAIL);

        return QualityCheck::query()->create([
            'document_type' => $type, 'document_id' => $documentId, 'document_line_id' => $lineId, 'item_id' => $item->id,
            'inventory_lot_id' => $lotId, 'warehouse_id' => $warehouseId, 'qc_checklist_template_id' => $template?->id,
            'results' => $results, 'outcome' => $outcome, 'inspected_quantity' => $inspected,
            'passed_quantity' => D::sub($inspected, $failed), 'failed_quantity' => $failed, 'notes' => $notes,
            'inspector_id' => $actor->id, 'inspected_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $check */
    private function fails(QcCheckType $type, mixed $answer, array $check): bool
    {
        $failValues = array_map('strval', (array) ($check['fail_values'] ?? []));
        $normalized = is_bool($answer) ? ($answer ? 'true' : 'false') : (string) $answer;
        if (in_array($normalized, $failValues, true)) {
            return true;
        }
        if ($type === QcCheckType::NUMERIC) {
            if (! is_numeric($answer)) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, "`{$check['label']}` needs a number.");
            }
            $value = (string) $answer;

            return (isset($check['min']) && D::lt($value, (string) $check['min'])) || (isset($check['max']) && D::gt($value, (string) $check['max']));
        }
        if ($type === QcCheckType::CHOICE && isset($check['choices']) && ! in_array($normalized, (array) $check['choices'], true)) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, "`{$check['label']}` must be one of: ".implode(', ', (array) $check['choices']).'.');
        }

        return false;
    }
}
