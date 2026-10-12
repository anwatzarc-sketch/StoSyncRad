<?php

declare(strict_types=1);

namespace App\Domains\CustomerPortal\Services;

use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\CustomerPortal\Enums\InvitationStatus;
use App\Domains\IdentityAccess\Enums\AccountStatus;
use App\Domains\IdentityAccess\Enums\AccountType;
use App\Domains\IdentityAccess\Models\Role;
use App\Domains\IdentityAccess\Models\RoleAssignment;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Permissions\PermissionSyncer;
use App\Domains\IdentityAccess\Services\Access;
use App\Domains\IdentityAccess\Services\SessionManager;
use App\Domains\IdentityAccess\Services\TotpService;
use App\Domains\Partners\Enums\PartnerType;
use App\Domains\Partners\Models\Partner;
use App\Domains\Platform\Config\PlatformConfig;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Customer portal membership: activates a customer account as a member of one customer (system role
 * "Customer portal member", or "Customer portal administrator" for the customer's first member and for
 * invited administrators), and lets a customer's administrators manage their colleagues — role,
 * deactivation and reactivation — never touching another customer's accounts. Two-step verification
 * must already be set up; accounts are never activated without it.
 */
final class MembershipService
{
    public function __construct(
        private readonly TotpService $totp,
        private readonly AuditRecorder $audit,
        private readonly SessionManager $sessions,
        private readonly Access $access,
        private readonly PlatformConfig $config,
    ) {}

    /** @param  bool|null  $admin  null = administrator only when the customer has no active administrator yet */
    public function activate(UserAccount $account, Partner $partner, ?UserAccount $actor, string $reason, ?bool $admin = null): void
    {
        if (! $account->isCustomer()) {
            throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'Only customer accounts can become customer portal members.');
        }
        if (! $partner->hasType(PartnerType::CUSTOMER)) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, "{$partner->name} is not a customer.", ['field' => 'partner_id']);
        }
        if (! $this->totp->isEnrolled($account)) {
            throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'Two-step verification must be set up before the account is activated.');
        }
        $admin ??= $this->activeAdmins($partner->id, except: $account->id) === 0;
        $account->forceFill(['customer_partner_id' => $partner->id, 'account_status' => AccountStatus::ACTIVE, 'updated_by' => $actor?->id])->save();
        $this->grant($account, $admin, $actor, $reason);
        $this->audit->record($actor, 'portal.membership.activated', 'user_accounts', $account->id, null,
            ['customer_partner_id' => $partner->id, 'customer' => $partner->name, 'administrator' => $admin], $reason);
    }

    public function isAdmin(UserAccount $account): bool
    {
        return RoleAssignment::query()->where('user_account_id', $account->id)->where('role_id', $this->role(Role::CUSTOMER_ADMIN_CODE)->id)->exists();
    }

    /**
     * The customer's portal accounts and open invitations, as shown on the Team page.
     *
     * @return array{members: array<int, array<string, mixed>>, invitations: array<int, array<string, mixed>>, max_members: int}
     */
    public function team(Partner $partner, UserAccount $viewer): array
    {
        $admins = DB::table('role_assignments')->where('role_id', $this->role(Role::CUSTOMER_ADMIN_CODE)->id)->pluck('user_account_id')->flip();
        $members = UserAccount::query()->where('customer_partner_id', $partner->id)->where('account_type', AccountType::CUSTOMER->value)
            ->with('person')->orderBy('created_at')->get()
            ->map(fn (UserAccount $a) => ['id' => $a->id, 'name' => $a->displayName(), 'email' => $a->email, 'status' => $a->account_status->value,
                'administrator' => isset($admins[$a->id]), 'is_me' => $a->id === $viewer->id, 'last_login_at' => $a->last_login_at?->toIso8601String(),
                'joined_at' => $a->created_at?->toIso8601String()])->all();
        $invitations = DB::table('customer_invitations as i')->leftJoin('user_accounts as u', 'u.id', '=', 'i.invited_by')
            ->where('i.partner_id', $partner->id)->where('i.status', InvitationStatus::PENDING->value)->where('i.expires_at', '>', now())->orderBy('i.created_at')
            ->get(['i.id', 'i.email', 'i.full_name', 'i.as_admin', 'i.expires_at', 'i.created_at', 'u.email as invited_by_email'])
            ->map(fn ($i) => ['id' => $i->id, 'email' => $i->email, 'full_name' => $i->full_name, 'administrator' => (bool) $i->as_admin,
                'expires_at' => Carbon::parse($i->expires_at)->toIso8601String(), 'invited_by' => $i->invited_by_email])->all();

        return ['members' => $members, 'invitations' => $invitations, 'max_members' => $this->config->int('portal.team.max_members')];
    }

    /** Refuses a new invitation when the customer has used up its portal accounts (portal.team.max_members). */
    public function assertSeatAvailable(string $partnerId): void
    {
        $max = $this->config->int('portal.team.max_members');
        if ($max === 0) {
            return;
        }
        $used = UserAccount::query()->where('customer_partner_id', $partnerId)->where('account_type', AccountType::CUSTOMER->value)
            ->whereIn('account_status', [AccountStatus::ACTIVE->value, AccountStatus::PENDING_ACTIVATION->value, AccountStatus::LOCKED->value])->count()
            + DB::table('customer_invitations')->where('partner_id', $partnerId)->where('status', InvitationStatus::PENDING->value)->where('expires_at', '>', now())->count();
        if ($used >= $max) {
            throw DomainException::of(ErrorCode::POLICY_VIOLATION, "This customer already has the most portal users allowed ($max). Remove a colleague or an open invitation first.");
        }
    }

    /** Make a colleague an administrator or a regular member. The customer always keeps one active administrator. */
    public function setAdministrator(string $memberId, bool $admin, UserAccount $actor): UserAccount
    {
        return DB::transaction(function () use ($memberId, $admin, $actor) {
            $member = $this->colleague($memberId, $actor);
            if (! $admin && $this->isAdmin($member) && $this->activeAdmins((string) $member->customer_partner_id, except: $member->id) === 0) {
                throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'Your company needs at least one administrator. Make someone else an administrator first.');
            }
            if ($member->account_status !== AccountStatus::ACTIVE && $admin) {
                throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'Only active colleagues can be made administrators.');
            }
            $this->grant($member, $admin, $actor, $admin ? 'Made customer portal administrator' : 'Made regular customer portal member');
            $this->sessions->afterPrivilegeChange($member);
            $this->audit->record($actor, 'portal.membership.role_changed', 'user_accounts', $member->id, null, ['administrator' => $admin]);

            return $member;
        });
    }

    /** Remove a colleague's portal access: the account is deactivated and every session and token ends. */
    public function deactivate(string $memberId, UserAccount $actor): UserAccount
    {
        return DB::transaction(function () use ($memberId, $actor) {
            $member = $this->colleague($memberId, $actor);
            if ($member->id === $actor->id) {
                throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'You cannot remove your own access. Ask another administrator.');
            }
            if ($member->account_status === AccountStatus::DEACTIVATED) {
                return $member;
            }
            if ($this->isAdmin($member) && $this->activeAdmins((string) $member->customer_partner_id, except: $member->id) === 0) {
                throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'Your company needs at least one administrator.');
            }
            $before = $member->account_status->value;
            $member->forceFill(['account_status' => AccountStatus::DEACTIVATED, 'updated_by' => $actor->id])->save();
            $this->sessions->revokeAll($member, includeTokens: true);
            $this->access->forget($member->id);
            $this->audit->record($actor, 'portal.membership.deactivated', 'user_accounts', $member->id, ['account_status' => $before], ['account_status' => 'deactivated']);

            return $member;
        });
    }

    /** Give a removed colleague access again (their two-step verification is still required). */
    public function reactivate(string $memberId, UserAccount $actor): UserAccount
    {
        return DB::transaction(function () use ($memberId, $actor) {
            $member = $this->colleague($memberId, $actor);
            if ($member->account_status !== AccountStatus::DEACTIVATED) {
                throw DomainException::of(ErrorCode::INVALID_STATE_TRANSITION, 'Only removed colleagues can be given access again.');
            }
            if (! $this->totp->isEnrolled($member)) {
                throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'This colleague never finished setting up two-step verification. Invite them again instead.');
            }
            $this->assertSeatAvailable((string) $member->customer_partner_id);
            $member->forceFill(['account_status' => AccountStatus::ACTIVE, 'updated_by' => $actor->id])->save();
            $this->access->forget($member->id);
            $this->audit->record($actor, 'portal.membership.reactivated', 'user_accounts', $member->id, ['account_status' => 'deactivated'], ['account_status' => 'active']);

            return $member;
        });
    }

    /**
     * Customers with active portal members but no administrator get their longest-standing active
     * member promoted (accounts created before customer administrators existed). Idempotent.
     */
    public function ensureAdministrators(): int
    {
        $adminRole = $this->role(Role::CUSTOMER_ADMIN_CODE);
        $partners = UserAccount::query()->where('account_type', AccountType::CUSTOMER->value)->where('account_status', AccountStatus::ACTIVE->value)
            ->whereNotNull('customer_partner_id')->distinct()->pluck('customer_partner_id');
        $promoted = 0;
        foreach ($partners as $partnerId) {
            if ($this->activeAdmins((string) $partnerId) > 0) {
                continue;
            }
            $first = UserAccount::query()->where('customer_partner_id', $partnerId)->where('account_type', AccountType::CUSTOMER->value)
                ->where('account_status', AccountStatus::ACTIVE->value)->orderBy('created_at')->first();
            if ($first !== null) {
                $this->grant($first, true, null, 'First member of the customer becomes its portal administrator');
                $this->audit->record(null, 'portal.membership.role_changed', 'user_accounts', $first->id, null, ['administrator' => true, 'role_id' => $adminRole->id]);
                $promoted++;
            }
        }

        return $promoted;
    }

    /** A portal account of the actor's own customer; anything else is "not found". */
    private function colleague(string $memberId, UserAccount $actor): UserAccount
    {
        $member = UserAccount::query()->lockForUpdate()->whereKey($memberId)->where('account_type', AccountType::CUSTOMER->value)
            ->whereNotNull('customer_partner_id')->where('customer_partner_id', $actor->customer_partner_id)->first();

        return $member ?? throw DomainException::notFound('colleague');
    }

    private function activeAdmins(string $partnerId, ?string $except = null): int
    {
        return DB::table('role_assignments as r')->join('user_accounts as u', 'u.id', '=', 'r.user_account_id')
            ->where('r.role_id', $this->role(Role::CUSTOMER_ADMIN_CODE)->id)->where('u.customer_partner_id', $partnerId)
            ->where('u.account_status', AccountStatus::ACTIVE->value)->when($except !== null, fn ($q) => $q->where('u.id', '<>', $except))->count();
    }

    /** Exactly one of the two customer roles. */
    private function grant(UserAccount $account, bool $admin, ?UserAccount $actor, string $reason): void
    {
        $keep = $this->role($admin ? Role::CUSTOMER_ADMIN_CODE : Role::CUSTOMER_MEMBER_CODE);
        $drop = $this->role($admin ? Role::CUSTOMER_MEMBER_CODE : Role::CUSTOMER_ADMIN_CODE);
        RoleAssignment::query()->where('user_account_id', $account->id)->where('role_id', $drop->id)->delete();
        RoleAssignment::query()->firstOrCreate(['user_account_id' => $account->id, 'role_id' => $keep->id, 'scope_type' => 'company', 'scope_id' => null],
            ['reason' => $reason, 'assigned_by' => $actor?->id]);
        $this->access->forget($account->id);
    }

    private function role(string $code): Role
    {
        $role = Role::query()->where('code', $code)->first();
        if ($role === null) {
            app(PermissionSyncer::class)->sync();
            $role = Role::query()->where('code', $code)->firstOrFail();
        }

        return $role;
    }
}
