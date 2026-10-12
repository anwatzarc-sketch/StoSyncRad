<?php

declare(strict_types=1);

namespace App\Domains\CustomerPortal\Services;

use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Partners\Enums\PartnerStatus;
use App\Domains\Partners\Models\Partner;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Database\Eloquent\Model;

/**
 * The customer a portal request acts for. The customer always comes from the signed-in account,
 * never from the request, and another customer's record behaves exactly like a missing one.
 */
final class CustomerContext
{
    /** Customer of a signed-in portal account. `$forAction` also requires the customer to be active. */
    public function partnerFor(?UserAccount $account, bool $forAction = false): Partner
    {
        if ($account === null || ! $account->isCustomer() || $account->customer_partner_id === null || ! $account->isActive()) {
            throw DomainException::of(ErrorCode::PERMISSION_DENIED, 'This area is for signed-in customers.');
        }
        $partner = Partner::query()->findOrFail($account->customer_partner_id);
        if ($forAction && $partner->status !== PartnerStatus::ACTIVE) {
            throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'Your customer account is on hold. Please contact us.', ['partner_status' => $partner->status->value]);
        }

        return $partner;
    }

    /**
     * A record that must belong to the customer.
     *
     * @template T of Model
     *
     * @param  class-string<T>  $class
     * @return T
     */
    public function own(string $class, string $id, Partner $partner, bool $lock = false, string $column = 'customer_partner_id'): Model
    {
        $query = $class::query()->whereKey($id)->where($column, $partner->id);
        $record = ($lock ? $query->lockForUpdate() : $query)->first();

        return $record ?? throw DomainException::notFound(strtolower(preg_replace('/(?<!^)[A-Z]/', ' $0', class_basename($class))));
    }
}
