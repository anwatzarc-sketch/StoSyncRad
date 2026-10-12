<?php

declare(strict_types=1);

namespace App\Domains\Partners\Models;

use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $partner_id
 * @property string $name
 * @property string|null $role
 * @property string|null $email
 * @property string|null $phone
 * @property bool $is_primary
 */
final class PartnerContact extends BaseModel
{
    protected $table = 'partner_contacts';

    protected $fillable = ['partner_id', 'name', 'role', 'email', 'phone', 'is_primary'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
