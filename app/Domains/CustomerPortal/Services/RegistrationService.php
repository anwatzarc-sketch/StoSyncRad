<?php

declare(strict_types=1);

namespace App\Domains\CustomerPortal\Services;

use App\Domains\CustomerPortal\Enums\RegistrationMode;
use App\Domains\CustomerPortal\Enums\RegistrationStatus as S;
use App\Domains\CustomerPortal\Models\CustomerRegistration;
use App\Domains\IdentityAccess\Actions\CreateUserAccountAction;
use App\Domains\IdentityAccess\Enums\AccountStatus;
use App\Domains\IdentityAccess\Enums\AccountType;
use App\Domains\IdentityAccess\Enums\PersonStatus;
use App\Domains\IdentityAccess\Models\Person;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Services\TotpService;
use App\Domains\Partners\Actions\SavePartnerAction;
use App\Domains\Partners\Enums\PartnerStatus;
use App\Domains\Partners\Enums\PartnerType;
use App\Domains\Partners\Models\Partner;
use App\Domains\Platform\Config\PlatformConfig;
use App\Support\Documents\ManagesDocuments;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Customer self-registration (CP-1.1 §4): register → confirm the e-mail address → set up two-step
 * verification → staff approve and link the account to a new or existing customer. A visitor can
 * never choose their customer, role, price or credit terms; until approval the account cannot sign in.
 */
final class RegistrationService
{
    use ManagesDocuments;

    private const RESENDS_PER_HOUR = 3;

    public function __construct(
        private readonly PlatformConfig $config,
        private readonly CreateUserAccountAction $accounts,
        private readonly TotpService $totp,
        private readonly MembershipService $membership,
        private readonly PortalMailer $mailer,
        private readonly SavePartnerAction $partners,
    ) {}

    /**
     * Returns null when the e-mail already has an account: the caller answers exactly as for a new
     * registration so the form cannot be used to discover accounts.
     *
     * @param  array<string, mixed>  $data  validated against PortalRegistrationSchema
     */
    public function register(array $data, ?string $ip): ?CustomerRegistration
    {
        $mode = RegistrationMode::from((string) $this->config->get('portal.registration.mode'));
        if ($mode !== RegistrationMode::APPROVAL) {
            throw DomainException::of(ErrorCode::POLICY_VIOLATION, $mode === RegistrationMode::INVITATION_ONLY
                ? 'New customer accounts are by invitation only. Please contact us to be invited.'
                : 'Customer registration is closed at the moment.');
        }
        if (($data['password'] ?? '') !== ($data['password_confirmation'] ?? null)) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The two passwords do not match.', ['field' => 'password_confirmation']);
        }
        $email = mb_strtolower(trim((string) $data['email']));
        if (UserAccount::query()->whereRaw('lower(email) = ?', [$email])->orWhereRaw('lower(username) = ?', [$email])->exists()) {
            return null;
        }
        $token = Str::random(64);
        $registration = DB::transaction(function () use ($data, $email, $ip, $token) {
            $account = $this->newAccount($data['full_name'], $email, $data['phone'] ?? null, (string) $data['password'], null);
            $registration = CustomerRegistration::query()->create([
                'user_account_id' => $account->id, 'person_id' => $account->person_id, 'company_name' => trim((string) $data['company_name']),
                'tax_id' => $data['tax_id'] ?? null, 'email' => $email, 'phone' => $data['phone'] ?? null, 'address' => $data['address'] ?? null,
                'message' => $data['message'] ?? null, 'status' => S::PENDING_VERIFICATION, 'email_token_hash' => hash('sha256', $token),
                'email_token_expires_at' => now()->addHours($this->config->int('portal.registration.verification_hours')),
                'suggested_partner_id' => $this->suggestCustomer((string) $data['company_name'], $data['tax_id'] ?? null, $email), 'ip_address' => $ip,
            ]);
            $this->audit(null, 'portal.registration.created', $registration, null, ['email' => $email, 'company_name' => $registration->company_name]);

            return $registration;
        });
        $this->mailer->send('email.portal.verify_email', $email, 'Confirm your email address', [
            'recipient_name' => $data['full_name'], 'company_name' => $registration->company_name, 'needs_approval' => true,
            'verify_url' => url('/portal/verify-email?registration='.$registration->id.'&token='.$token),
            'expires_hours' => $this->config->int('portal.registration.verification_hours'),
        ]);

        return $registration;
    }

    /**
     * Send a new confirmation link for a registration that is still waiting for its e-mail confirmation
     * or its two-step verification set-up. The new link replaces any earlier one. Unknown addresses,
     * finished registrations and accounts are answered the same way by the caller (nothing is sent), so
     * the form cannot be used to discover accounts; every address is limited to a few links per hour.
     */
    public function resendVerification(string $email): void
    {
        $email = mb_strtolower(trim($email));
        $key = 'portal-verification-resend|'.hash('sha256', $email);
        if (RateLimiter::tooManyAttempts($key, self::RESENDS_PER_HOUR)) {
            throw DomainException::of(ErrorCode::RATE_LIMITED, 'Too many links were requested for this address. Try again in '
                .max(1, (int) ceil(RateLimiter::availableIn($key) / 60)).' minutes.');
        }
        RateLimiter::hit($key, 3600);

        $token = Str::random(64);
        $registration = DB::transaction(function () use ($email, $token) {
            $registration = CustomerRegistration::query()->whereRaw('lower(email) = ?', [$email])
                ->whereIn('status', [S::PENDING_VERIFICATION->value, S::PENDING_MFA->value])->orderByDesc('created_at')->lockForUpdate()->first();
            if ($registration === null) {
                return null;
            }
            $registration->forceFill(['email_token_hash' => hash('sha256', $token),
                'email_token_expires_at' => now()->addHours($this->config->int('portal.registration.verification_hours'))])->save();
            $this->audit(null, 'portal.registration.verification_resent', $registration, null, ['email' => $email]);

            return $registration;
        });
        if ($registration === null) {
            return;
        }
        $this->mailer->send('email.portal.verify_email', $email, 'Confirm your email address', [
            'recipient_name' => (string) (Person::query()->whereKey($registration->person_id)->value('full_name') ?? $registration->company_name),
            'company_name' => $registration->company_name, 'needs_approval' => $registration->invitation_id === null,
            'verify_url' => url('/portal/verify-email?registration='.$registration->id.'&token='.$token),
            'expires_hours' => $this->config->int('portal.registration.verification_hours'),
        ]);
    }

    /** E-mail link: proves the address. Repeating a used link simply continues the registration. */
    public function verifyEmail(string $id, string $token): CustomerRegistration
    {
        return DB::transaction(function () use ($id, $token) {
            $invalid = fn () => DomainException::of(ErrorCode::VALIDATION_FAILED, 'This link is invalid or has expired. Ask for a new link below, or contact us.');
            $registration = CustomerRegistration::query()->lockForUpdate()->find($id) ?? throw $invalid();
            if ($registration->status === S::PENDING_MFA && $registration->email_verified_at !== null && $registration->email_token_hash === null) {
                return $registration;
            }
            if ($registration->status === S::PENDING_MFA && $registration->email_token_hash !== null) {
                // A new link sent to finish two-step verification set-up.
                if (! hash_equals($registration->email_token_hash, hash('sha256', $token)) || $registration->email_token_expires_at?->isPast()) {
                    throw $invalid();
                }
                $registration->forceFill(['email_token_hash' => null])->save();

                return $registration;
            }
            if ($registration->status !== S::PENDING_VERIFICATION || $registration->email_token_hash === null
                || ! hash_equals($registration->email_token_hash, hash('sha256', $token)) || $registration->email_token_expires_at?->isPast()) {
                throw $invalid();
            }
            $this->transition($registration, S::PENDING_MFA, ['email_verified_at' => now(), 'email_token_hash' => null]);
            $this->audit(null, 'portal.registration.email_verified', $registration);

            return $registration;
        });
    }

    /** @return array{secret: string, otpauth_uri: string, qr_svg: string} */
    public function beginSecurity(string $id): array
    {
        $registration = $this->pendingMfa($id);
        $account = UserAccount::query()->findOrFail($registration->user_account_id);

        return $this->totp->beginEnrollment($account);
    }

    /**
     * Confirm two-step verification. Invited contacts are activated now (staff chose the customer);
     * self-registrations wait for approval.
     *
     * @return array{status: string, recovery_codes: array<int, string>}
     */
    public function confirmSecurity(string $id, string $code): array
    {
        return DB::transaction(function () use ($id, $code) {
            $registration = $this->pendingMfa($id, lock: true);
            $account = UserAccount::query()->lockForUpdate()->findOrFail($registration->user_account_id);
            $codes = $this->totp->confirmEnrollment($account, $code)
                ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The code is incorrect. Scan the QR code again and enter the current code.', ['field' => 'code']);
            if ($registration->invitation_id !== null) {
                $partner = Partner::query()->findOrFail($registration->partner_id);
                $asAdmin = (bool) DB::table('customer_invitations')->where('id', $registration->invitation_id)->value('as_admin');
                $this->membership->activate($account, $partner, null, 'Accepted customer portal invitation', $asAdmin ? true : null);
                $this->transition($registration, S::APPROVED, ['mfa_enrolled_at' => now(), 'decided_at' => now()]);
            } else {
                $this->transition($registration, S::PENDING_APPROVAL, ['mfa_enrolled_at' => now()]);
                $this->publish('portal.registration.submitted', $registration, ['company_name' => $registration->company_name, 'email' => $registration->email]);
            }
            $this->audit(null, 'portal.registration.security_confirmed', $registration);

            return ['status' => $registration->status->value, 'recovery_codes' => $codes];
        });
    }

    /**
     * Staff decision: link to an existing customer (`partner_id`) or create one from the registration.
     *
     * @param  array<string, mixed>  $data  validated against RegistrationApprovalSchema
     */
    public function approve(string $id, UserAccount $actor, array $data): CustomerRegistration
    {
        $registration = DB::transaction(function () use ($id, $actor, $data) {
            /** @var CustomerRegistration $registration */
            $registration = $this->lockDocument(CustomerRegistration::class, $id);
            $this->expectVersion($registration, isset($data['version']) ? (int) $data['version'] : null);
            $this->assertStatus($registration, [S::PENDING_APPROVAL]);
            if (! empty($data['partner_id'])) {
                $partner = Partner::query()->findOrFail($data['partner_id']);
                if (! $partner->hasType(PartnerType::CUSTOMER) || in_array($partner->status, [PartnerStatus::BLACKLISTED, PartnerStatus::ARCHIVED], true)) {
                    throw DomainException::of(ErrorCode::VALIDATION_FAILED, "{$partner->name} is not an active customer.", ['field' => 'partner_id']);
                }
            } elseif (! empty($data['create_customer'])) {
                $partner = $this->partners->execute(['code' => $this->newCustomerCode(), 'name' => $registration->company_name, 'legal_name' => $registration->company_name,
                    'partner_types' => [PartnerType::CUSTOMER->value], 'tax_id' => $registration->tax_id, 'email' => $registration->email,
                    'phone' => $registration->phone, 'addresses' => $registration->address === null ? null : [$registration->address]], $actor);
            } else {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Choose the existing customer to link, or create a new customer.', ['field' => 'partner_id']);
            }
            $account = UserAccount::query()->lockForUpdate()->findOrFail($registration->user_account_id);
            $this->membership->activate($account, $partner, $actor, 'Approved customer portal registration');
            $this->transition($registration, S::APPROVED, ['partner_id' => $partner->id, 'decided_by' => $actor->id, 'decided_at' => now(),
                'decision_reason' => $data['comment'] ?? null]);
            $this->audit($actor, 'portal.registration.approved', $registration, null, ['partner_id' => $partner->id, 'customer' => $partner->name]);

            return $registration;
        });
        $this->mailer->send('email.portal.registration_decided', $registration->email, 'Your customer portal account is ready', [
            'recipient_name' => Person::query()->whereKey($registration->person_id)->value('full_name'), 'company_name' => $registration->company_name,
            'approved' => true, 'login_url' => url('/portal/login'),
        ]);

        return $registration;
    }

    public function reject(string $id, UserAccount $actor, ?string $reason, ?int $version = null): CustomerRegistration
    {
        if (trim((string) $reason) === '') {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Give the reason the customer will see.', ['field' => 'reason']);
        }
        $registration = DB::transaction(function () use ($id, $actor, $reason, $version) {
            /** @var CustomerRegistration $registration */
            $registration = $this->lockDocument(CustomerRegistration::class, $id);
            $this->expectVersion($registration, $version);
            $this->assertStatus($registration, [S::PENDING_VERIFICATION, S::PENDING_MFA, S::PENDING_APPROVAL]);
            UserAccount::query()->whereKey($registration->user_account_id)->update(['account_status' => AccountStatus::DEACTIVATED->value,
                'deactivated_at' => now(), 'deactivated_by' => $actor->id, 'updated_at' => now()]);
            $this->transition($registration, S::REJECTED, ['decided_by' => $actor->id, 'decided_at' => now(), 'decision_reason' => $reason, 'email_token_hash' => null]);
            $this->audit($actor, 'portal.registration.rejected', $registration, null, null, $reason);

            return $registration;
        });
        if ($registration->email_verified_at !== null) {
            $this->mailer->send('email.portal.registration_decided', $registration->email, 'Your customer portal registration', [
                'recipient_name' => Person::query()->whereKey($registration->person_id)->value('full_name'), 'company_name' => $registration->company_name,
                'approved' => false, 'reason' => $reason,
            ]);
        }

        return $registration;
    }

    /** Person + inactive customer account (no customer linked until approval or invitation). */
    public function newAccount(string $fullName, string $email, ?string $phone, string $password, ?string $partnerId): UserAccount
    {
        $person = new Person(['full_name' => trim($fullName), 'email' => $email, 'phone' => $phone]);
        $person->forceFill(['status' => PersonStatus::ACTIVE])->save();

        return $this->accounts->execute(['person_id' => $person->id, 'username' => $email, 'email' => $email, 'password' => $password,
            'account_type' => AccountType::CUSTOMER->value], null, activate: false, portal: true, customerPartnerId: $partnerId);
    }

    private function pendingMfa(string $id, bool $lock = false): CustomerRegistration
    {
        $query = CustomerRegistration::query()->whereKey($id);
        $registration = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($registration === null || $registration->status !== S::PENDING_MFA) {
            throw DomainException::of(ErrorCode::UNAUTHENTICATED, 'Open the link from your e-mail again to continue.');
        }

        return $registration;
    }

    /** Suggest (never decide) the existing customer this registration probably belongs to. */
    private function suggestCustomer(string $company, ?string $taxId, string $email): ?string
    {
        $customers = Partner::query()->whereRaw("'customer' MEMBER OF (partner_types)")->whereNotIn('status', [PartnerStatus::ARCHIVED->value]);
        if ($taxId !== null && trim($taxId) !== '') {
            $match = (clone $customers)->whereRaw('lower(tax_id) = ?', [mb_strtolower(trim($taxId))])->value('id');
            if ($match !== null) {
                return $match;
            }
        }

        return (clone $customers)->where(fn ($q) => $q->whereRaw('lower(email) = ?', [$email])->orWhereRaw('lower(name) = ?', [mb_strtolower(trim($company))]))->value('id');
    }

    private function newCustomerCode(): string
    {
        $next = (int) Partner::query()->where('code', 'like', 'CUS-%')->count() + 1;
        do {
            $code = sprintf('CUS-%05d', $next++);
        } while (Partner::query()->where('code', $code)->exists());

        return $code;
    }
}
