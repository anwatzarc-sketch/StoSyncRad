<?php

declare(strict_types=1);

namespace App\Domains\CustomerPortal\Models;

use App\Domains\CustomerPortal\Enums\InvitationStatus;
use App\Domains\Partners\Models\Partner;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Staff invitation for a contact of an existing customer to open a portal account. Only the token hash is stored. */
final class CustomerInvitation extends BaseModel
{
    protected $table = 'customer_invitations';

    protected $fillable = ['partner_id', 'email', 'full_name', 'token_hash', 'status', 'expires_at', 'invited_by', 'accepted_account_id', 'accepted_at', 'revoked_by', 'revoked_at', 'version', 'as_admin'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'status' => InvitationStatus::class,
            'as_admin' => 'boolean',
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }
}
