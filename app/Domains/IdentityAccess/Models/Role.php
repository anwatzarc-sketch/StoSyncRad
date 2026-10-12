<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Models;

use App\Domains\IdentityAccess\Enums\RoleStatus;
use App\Domains\IdentityAccess\Schemas\RoleSchema;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Admin-defined role: pure data (Chapter 04 §4.5).
 *
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property bool $is_system
 * @property bool $is_template_origin
 * @property RoleStatus $status
 */
final class Role extends BaseModel
{
    public const SUPER_ADMIN_CODE = '__super_admin';

    /** System role of Customer Portal accounts (customer.* permissions only, without managing colleagues). */
    public const CUSTOMER_MEMBER_CODE = '__customer_member';

    /** System role of a customer's portal administrators: every customer.* permission, including managing colleagues. */
    public const CUSTOMER_ADMIN_CODE = '__customer_admin';

    protected $table = 'roles';

    protected static ?string $schema = RoleSchema::class;

    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'is_template_origin' => 'boolean', 'status' => RoleStatus::class];
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(RolePermission::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }
}
