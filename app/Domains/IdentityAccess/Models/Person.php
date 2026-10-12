<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Models;

use App\Domains\IdentityAccess\Enums\PersonStatus;
use App\Domains\IdentityAccess\Schemas\PersonSchema;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A natural person (employee/contractor). Owns zero or more user accounts (Chapter 04 §4.1).
 *
 * @property string $id
 * @property string $full_name
 * @property string|null $employee_number
 * @property string|null $national_id  encrypted
 * @property string|null $national_id_hash
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $branch_id
 * @property string|null $department_lookup_value_id
 * @property string|null $job_title
 * @property PersonStatus $status
 */
final class Person extends BaseModel
{
    protected $table = 'persons';

    protected static ?string $schema = PersonSchema::class;

    protected $hidden = ['national_id', 'national_id_hash'];

    protected function casts(): array
    {
        return [
            'status' => PersonStatus::class,
            'national_id' => 'encrypted',
            'archived_at' => 'immutable_datetime',
        ];
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(UserAccount::class);
    }

    public static function hashNationalId(?string $nationalId): ?string
    {
        return $nationalId === null || $nationalId === '' ? null : hash_hmac('sha256', strtolower(trim($nationalId)), (string) config('app.key'));
    }
}
