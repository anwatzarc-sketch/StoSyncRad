<?php

declare(strict_types=1);

namespace App\Domains\Platform\Seeds;

use App\Domains\CustomerPortal\Services\MembershipService;

/** Every customer with portal members gets an administrator (its longest-standing active member). */
final class CustomerAdministratorsSeed extends Seed
{
    public function name(): string
    {
        return 'customer_administrators';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function run(array $options = []): array
    {
        return ['promoted' => app(MembershipService::class)->ensureAdministrators()];
    }
}
