<?php

declare(strict_types=1);

namespace App\Domains\Organization\Actions;

use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Messaging\Services\Outbox;
use App\Domains\Organization\Enums\BranchStatus;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Schemas\BranchSchema;
use Illuminate\Support\Facades\DB;

final class CreateBranchAction
{
    public function __construct(private readonly AuditRecorder $audit, private readonly Outbox $outbox) {}

    /** @param  array<string, mixed>  $data  validated against BranchSchema */
    public function execute(array $data, ?UserAccount $actor): Branch
    {
        return DB::transaction(function () use ($data, $actor) {
            $branch = new Branch(BranchSchema::columns($data));
            $branch->forceFill(['status' => BranchStatus::ACTIVE, 'created_by' => $actor?->id, 'updated_by' => $actor?->id])->save();

            $this->audit->record($actor, 'organization.branch.created', 'branch', $branch->id, null, $branch->only(['code', 'name', 'kind']));
            $this->outbox->publish('organization.branch.created', 'branch', $branch->id, ['code' => $branch->code]);

            return $branch;
        });
    }
}
