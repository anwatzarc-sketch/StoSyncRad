<?php

declare(strict_types=1);

namespace App\Domains\CustomerPortal\Services;

use App\Domains\CustomerPortal\Enums\InvitationStatus as I;
use App\Domains\CustomerPortal\Enums\RegistrationMode;
use App\Domains\CustomerPortal\Enums\RegistrationStatus;
use App\Domains\CustomerPortal\Models\CustomerInvitation;
use App\Domains\CustomerPortal\Models\CustomerRegistration;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Partners\Enums\PartnerStatus;
use App\Domains\Partners\Enums\PartnerType;
use App\Domains\Partners\Models\Partner;
use App\Domains\Platform\Config\PlatformConfig;
use App\Support\Documents\ManagesDocuments;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Existing-customer invitations (CP-1.1 §4): staff invite a contact of a customer, or a customer's
 * portal administrator invites a colleague (always into their own customer); the contact sets a
 * password and two-step verification and becomes a member of that customer without further approval
 * (the invitation is the approval). The link proves the e-mail address.
 */
final class InvitationService
{
    use ManagesDocuments;

    public function __construct(
        private readonly PlatformConfig $config,
        private readonly RegistrationService $registrations,
        private readonly PortalMailer $mailer,
        private readonly MembershipService $membership,
    ) {}

    /** @param  array<string, mixed>  $data  validated against CustomerInvitationSchema */
    public function create(array $data, UserAccount $actor): CustomerInvitation
    {
        if (RegistrationMode::from((string) $this->config->get('portal.registration.mode')) === RegistrationMode::CLOSED) {
            throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'Customer registration is closed (portal.registration.mode).');
        }
        // A customer administrator can only ever invite into their own customer.
        $partner = Partner::query()->findOrFail($actor->isCustomer() ? $actor->customer_partner_id : $data['partner_id']);
        if (! $partner->hasType(PartnerType::CUSTOMER) || $partner->status !== PartnerStatus::ACTIVE) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, "{$partner->name} is not an active customer.", ['field' => 'partner_id']);
        }
        $email = mb_strtolower(trim((string) $data['email']));
        if (UserAccount::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
            throw DomainException::of(ErrorCode::DUPLICATE_RESOURCE, 'An account with this e-mail address already exists.', ['field' => 'email']);
        }
        $token = Str::random(64);
        $invitation = DB::transaction(function () use ($data, $actor, $partner, $email, $token) {
            CustomerInvitation::query()->where('partner_id', $partner->id)->whereRaw('lower(email) = ?', [$email])->where('status', I::PENDING->value)
                ->update(['status' => I::REVOKED->value, 'revoked_by' => $actor->id, 'revoked_at' => now(), 'updated_at' => now()]);
            DB::table('partners')->where('id', $partner->id)->lockForUpdate()->value('id');   // one seat check at a time per customer
            $this->membership->assertSeatAvailable($partner->id);
            $invitation = CustomerInvitation::query()->create(['partner_id' => $partner->id, 'email' => $email, 'full_name' => trim((string) $data['full_name']),
                'token_hash' => hash('sha256', $token), 'status' => I::PENDING, 'invited_by' => $actor->id, 'as_admin' => (bool) ($data['as_admin'] ?? false),
                'expires_at' => now()->addDays($this->config->int('portal.invitation.valid_days'))]);
            $this->audit($actor, 'portal.invitation.created', $invitation, null, ['partner_id' => $partner->id, 'email' => $email,
                'as_admin' => $invitation->as_admin, 'by_customer' => $actor->isCustomer()]);

            return $invitation;
        });
        $this->mailer->send('email.portal.invitation', $email, $actor->isCustomer() ? $actor->displayName().' invited you to our customer portal'
            : 'You are invited to our customer portal', [
                'recipient_name' => $invitation->full_name, 'customer_name' => $partner->name, 'expires_days' => $this->config->int('portal.invitation.valid_days'),
                'invitation_url' => url('/portal/invitation?invitation='.$invitation->id.'&token='.$token),
                'inviter_name' => $actor->isCustomer() ? $actor->displayName() : '',
            ]);

        return $invitation;
    }

    /** Public details shown on the invitation page (valid link only). @return array<string, string> */
    public function preview(string $id, string $token): array
    {
        $invitation = $this->valid($id, $token);

        return ['email' => $invitation->email, 'full_name' => $invitation->full_name, 'customer_name' => (string) Partner::query()->whereKey($invitation->partner_id)->value('name')];
    }

    /**
     * Create the invited contact's account; two-step verification comes next.
     *
     * @param  array<string, mixed>  $data  validated against PortalInvitationAcceptSchema
     */
    public function accept(string $id, string $token, array $data, ?string $ip): CustomerRegistration
    {
        if (($data['password'] ?? '') !== ($data['password_confirmation'] ?? null)) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The two passwords do not match.', ['field' => 'password_confirmation']);
        }

        return DB::transaction(function () use ($id, $token, $data, $ip) {
            $invitation = $this->valid($id, $token, lock: true);
            if (UserAccount::query()->whereRaw('lower(email) = ?', [$invitation->email])->exists()) {
                throw DomainException::of(ErrorCode::DUPLICATE_RESOURCE, 'An account with this e-mail address already exists. Sign in instead.');
            }
            $partner = Partner::query()->findOrFail($invitation->partner_id);
            $account = $this->registrations->newAccount((string) ($data['full_name'] ?? $invitation->full_name), $invitation->email, $data['phone'] ?? null,
                (string) $data['password'], $partner->id);
            $registration = CustomerRegistration::query()->create([
                'user_account_id' => $account->id, 'person_id' => $account->person_id, 'invitation_id' => $invitation->id, 'company_name' => $partner->name,
                'tax_id' => $partner->tax_id, 'email' => $invitation->email, 'phone' => $data['phone'] ?? null, 'status' => RegistrationStatus::PENDING_MFA,
                'email_verified_at' => now(), 'partner_id' => $partner->id, 'ip_address' => $ip,
            ]);
            $this->transition($invitation, I::ACCEPTED, ['accepted_account_id' => $account->id, 'accepted_at' => now()]);
            $this->audit(null, 'portal.invitation.accepted', $invitation, null, ['account_id' => $account->id]);

            return $registration;
        });
    }

    public function revoke(string $id, UserAccount $actor, ?int $version = null): CustomerInvitation
    {
        return DB::transaction(function () use ($id, $actor, $version) {
            /** @var CustomerInvitation $invitation */
            $invitation = $this->lockDocument(CustomerInvitation::class, $id);
            if ($actor->isCustomer() && $invitation->partner_id !== $actor->customer_partner_id) {
                throw DomainException::notFound('invitation');
            }
            $this->expectVersion($invitation, $version);
            $this->transition($invitation, I::REVOKED, ['revoked_by' => $actor->id, 'revoked_at' => now()]);
            $this->audit($actor, 'portal.invitation.revoked', $invitation);

            return $invitation;
        });
    }

    private function valid(string $id, string $token, bool $lock = false): CustomerInvitation
    {
        $invalid = fn () => DomainException::of(ErrorCode::VALIDATION_FAILED, 'This invitation is no longer valid. Ask us for a new one.');
        $query = CustomerInvitation::query()->whereKey($id);
        $invitation = ($lock ? $query->lockForUpdate() : $query)->first() ?? throw $invalid();
        if ($invitation->status !== I::PENDING || ! hash_equals($invitation->token_hash, hash('sha256', $token))) {
            throw $invalid();
        }
        if ($invitation->expires_at->isPast()) {
            if ($lock) {
                $this->transition($invitation, I::EXPIRED);
            }
            throw $invalid();
        }

        return $invitation;
    }
}
