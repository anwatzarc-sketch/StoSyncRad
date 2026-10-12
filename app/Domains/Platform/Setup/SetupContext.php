<?php

declare(strict_types=1);

namespace App\Domains\Platform\Setup;

use App\Domains\IdentityAccess\Models\UserAccount;

/** Who is running setup and how (wizard or headless bundle). */
final class SetupContext
{
    /** @param  array<string, mixed>  $session  setup-session scratch data (e.g. pending MFA secret) */
    public function __construct(
        public readonly ?UserAccount $actor,
        public readonly bool $headless = false,
        public array $session = [],
    ) {}
}
