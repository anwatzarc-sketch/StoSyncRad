<?php

declare(strict_types=1);

namespace App\Domains\Partners\Actions;

use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Partners\Enums\PartnerStatus;
use App\Domains\Partners\Models\Partner;
use App\Domains\Partners\Schemas\PartnerSchema;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Support\Facades\DB;

/** Creates or updates a partner (Chapter 06 §6.9). Status changes go through ChangePartnerStatusAction. */
final class SavePartnerAction
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** @param  array<string, mixed>  $data  validated against PartnerSchema */
    public function execute(array $data, ?UserAccount $actor, ?Partner $partner = null): Partner
    {
        return DB::transaction(function () use ($data, $actor, $partner) {
            if (($data['is_internal'] ?? $partner?->is_internal) && empty($data['internal_branch_id'] ?? $partner?->internal_branch_id)
                && in_array('internal_recipient', (array) ($data['partner_types'] ?? $partner?->partner_types ?? []), true) === false) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Internal partners must be internal recipients or reference a branch.');
            }
            $before = $partner?->only(array_keys($data));
            $partner ??= new Partner;
            if ($partner->exists) {
                $partner = Partner::query()->lockForUpdate()->findOrFail($partner->id);
                unset($data['code']);
            }
            $partner->fill(PartnerSchema::columns($data));
            $partner->forceFill([
                'status' => $partner->status ?? PartnerStatus::ACTIVE,
                'created_by' => $partner->created_by ?? $actor?->id,
                'updated_by' => $actor?->id,
            ])->save();
            $this->audit->record($actor, $before === null ? 'partners.partner.created' : 'partners.partner.updated', 'partner', $partner->id,
                $before, $partner->only(array_keys($data)));

            return $partner;
        });
    }
}
