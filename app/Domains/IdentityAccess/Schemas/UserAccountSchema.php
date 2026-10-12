<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Schemas;

use App\Domains\IdentityAccess\Enums\AccountStatus;
use App\Domains\IdentityAccess\Enums\AccountType;
use App\Domains\IdentityAccess\Enums\MfaStatus;
use App\Domains\IdentityAccess\Models\Person;
use App\Support\Enums\DataClassification;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class UserAccountSchema extends TableSchema
{
    public static function table(): string
    {
        return 'user_accounts';
    }

    public static function stateColumn(): string
    {
        return 'account_status';
    }

    protected static function define(): array
    {
        return [
            'person_id' => Property::uuid()->requiredOnCreate()->references(Person::class)->label('Person'),
            'username' => Property::string(64)->requiredOnCreate()->trim()->pattern('/^[A-Za-z0-9._@+-]{3,64}$/')->unique()->searchable()->label('Username'),
            'email' => Property::email()->requiredOnCreate()->trim()->unique()->searchable()->classification(DataClassification::PERSONAL_DATA->value)->label('Email'),
            'password' => Property::string(256)->nullable()->sensitive()->notReportable()->label('Password')->virtual(),
            'account_type' => Property::enum(AccountType::class)->default(AccountType::HUMAN->value)->filterable()->label('Account type'),
            'account_status' => Property::enum(AccountStatus::class)->serverControlled()->filterable()->label('Status'),
            'is_super_admin' => Property::boolean()->serverControlled()->label('Super admin'),
            'mfa_status' => Property::enum(MfaStatus::class)->serverControlled()->label('MFA status'),
            'must_change_password' => Property::boolean()->default(false)->writableBy('identity.account.force_password_change')->label('Must change password'),
            'password_changed_at' => Property::datetime()->nullable()->serverControlled(),
            'last_login_at' => Property::datetime()->nullable()->serverControlled(),
            'last_login_ip' => Property::string(45)->nullable()->serverControlled()->sensitive(),
            'failed_login_count' => Property::integer()->serverControlled(),
            'locked_until' => Property::datetime()->nullable()->serverControlled(),
            'deactivated_at' => Property::datetime()->nullable()->serverControlled(),
            'deactivated_by' => Property::uuid()->nullable()->serverControlled(),
        ];
    }
}
