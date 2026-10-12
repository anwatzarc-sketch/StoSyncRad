<?php

declare(strict_types=1);

namespace App\Domains\CustomerPortal\Models;

use App\Domains\CustomerPortal\Enums\RegistrationStatus;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Partners\Models\Partner;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A request to open a Customer Portal account. Business details stay unverified until staff approve it and link a customer. */
final class CustomerRegistration extends BaseModel
{
    protected $table = 'customer_registrations';

    protected $fillable = ['user_account_id', 'person_id', 'invitation_id', 'company_name', 'tax_id', 'email', 'phone', 'address', 'message', 'status', 'email_token_hash', 'email_token_expires_at', 'email_verified_at', 'mfa_enrolled_at', 'suggested_partner_id', 'partner_id', 'decided_by', 'decided_at', 'decision_reason', 'ip_address', 'version'];

    protected $hidden = ['email_token_hash'];

    protected function casts(): array
    {
        return [
            'status' => RegistrationStatus::class,
            'address' => 'array',
            'email_token_expires_at' => 'immutable_datetime',
            'email_verified_at' => 'immutable_datetime',
            'mfa_enrolled_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(UserAccount::class, 'user_account_id');
    }

    public function suggestedPartner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'suggested_partner_id');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(CustomerInvitation::class, 'invitation_id');
    }
}
