<?php

declare(strict_types=1);

namespace App\Domains\Platform\Config\Keys;

use App\Domains\Platform\Config\DefinesConfigKeys;
use App\Domains\Platform\Config\ProvidesConfigKeys;

/**
 * Approval tiers and SoD rules live in their own tables (approval_policies, sod_rules);
 * these keys tune how they are applied.
 */
final class ApprovalKeys implements ProvidesConfigKeys
{
    use DefinesConfigKeys;

    public static function definitions(): array
    {
        return [
            self::bool('approval.sod_override_requires_reason', true, 'SoD override reason', 'Overriding a recommended SoD rule requires a written reason.'),
            self::bool('approval.require_comment_on_reject', true, 'Rejection comment', 'Rejecting a document requires a comment.'),
            self::bool('approval.notify_next_approver', true, 'Notify next approver', 'Notify eligible approvers when a document awaits their decision.'),
        ];
    }
}
