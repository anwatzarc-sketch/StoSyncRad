<?php

declare(strict_types=1);

namespace App\Domains\Platform\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/**
 * Ordered Platform Setup steps (Chapter 02 §2.5). Order = declaration order.
 */
enum PlatformSetupStep: string implements SystemEnum
{
    use HasSystemEnum;

    case WELCOME = 'welcome';
    case SUPER_ADMIN = 'super_admin';
    case COMPANY_PROFILE = 'company_profile';
    case BRAND_KIT = 'brand_kit';
    case LOCALIZATION = 'localization';
    case IDENTITY_SECURITY = 'identity_security';
    case ORGANIZATION = 'organization';
    case CATALOG_FOUNDATIONS = 'catalog_foundations';
    case PARTNERS = 'partners';
    case DOCUMENT_NUMBERING = 'document_numbering';
    case STOCK_APPROVAL_POLICIES = 'stock_approval_policies';
    case ROLES_ACCESS = 'roles_access';
    case REPORTING = 'reporting';
    case NOTIFICATIONS_INTEGRATIONS = 'notifications_integrations';
    case OPENING_DATA = 'opening_data';
    case REVIEW_LOCK = 'review_lock';

    public function order(): int
    {
        return array_search($this, self::cases(), true);
    }

    public function previous(): ?self
    {
        $i = $this->order();

        return $i === 0 ? null : self::cases()[$i - 1];
    }

    public function next(): ?self
    {
        $i = $this->order();

        return $i === count(self::cases()) - 1 ? null : self::cases()[$i + 1];
    }

    public function isOptional(): bool
    {
        return in_array($this, [self::PARTNERS, self::OPENING_DATA, self::NOTIFICATIONS_INTEGRATIONS], true);
    }

    /** Steps that require an authenticated Super Admin (everything after account creation). */
    public function requiresSuperAdmin(): bool
    {
        return $this->order() > self::SUPER_ADMIN->order();
    }
}
