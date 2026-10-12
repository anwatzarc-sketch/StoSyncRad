<?php

declare(strict_types=1);

namespace App\Domains\CustomerPortal\Schemas;

use App\Domains\Partners\Models\Partner;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Approve a registration by linking an existing customer or creating a new one. */
final class RegistrationApprovalSchema extends TableSchema
{
    public static function table(): string
    {
        return 'customer_registrations';
    }

    protected static function define(): array
    {
        return [
            'partner_id' => Property::uuid()->nullable()->references(Partner::class)->virtual()->label('Existing customer'),
            'create_customer' => Property::boolean()->nullable()->virtual()->label('Create a new customer from the registration'),
            'comment' => Property::text(2000)->nullable()->trim()->virtual()->label('Comment'),
            'version' => Property::integer()->nullable()->min(1)->virtual()->label('Expected version'),
        ];
    }
}
