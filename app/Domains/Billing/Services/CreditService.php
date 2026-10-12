<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Fulfillment\Models\SalesOrder;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Services\Access;
use App\Domains\Partners\Enums\PartnerType;
use App\Domains\Partners\Models\Partner;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;
use Illuminate\Support\Facades\DB;

/**
 * Customer credit limits. Exposure = unpaid invoice balances + the not-yet-invoiced value of open
 * orders. The portal refuses orders and quotation acceptances that would go over the limit; staff may
 * confirm such an order only with `billing.credit.override` and a recorded reason. No limit set = no check.
 */
final class CreditService
{
    public function __construct(private readonly Access $access, private readonly AuditRecorder $audit) {}

    /** @return array{limit: ?string, exposure: string, available: ?string, currency: ?string} */
    public function summary(Partner $customer): array
    {
        $limit = $customer->credit_limit === null ? null : D::exact((string) $customer->credit_limit, D::MONEY_SCALE);
        $exposure = $this->exposure($customer->id, includeDrafts: true);

        return ['limit' => $limit, 'exposure' => $exposure, 'available' => $limit === null ? null : D::max('0', D::sub($limit, $exposure, D::MONEY_SCALE)),
            'currency' => $customer->default_currency];
    }

    /** Unpaid invoices + uninvoiced open orders (drafts included when the customer is adding more). */
    public function exposure(string $customerId, bool $includeDrafts, ?string $excludeOrderId = null): string
    {
        $receivable = (string) DB::table('invoices')->where('customer_partner_id', $customerId)->whereIn('status', ['issued', 'partially_paid'])->sum('balance_due');
        $orders = DB::table('sales_orders as o')->where('o.customer_partner_id', $customerId)->whereNotIn('o.status', ['cancelled', 'returned'])
            ->when(! $includeDrafts, fn ($q) => $q->where('o.status', '<>', 'draft'))
            ->when($excludeOrderId !== null, fn ($q) => $q->where('o.id', '<>', $excludeOrderId))
            ->selectRaw("COALESCE(SUM(GREATEST(o.total - COALESCE((SELECT SUM(v.subtotal) FROM invoices v WHERE v.sales_order_id = o.id AND v.status <> 'void'), 0), 0)), 0) AS open")
            ->value('open');

        return D::add(D::exact($receivable, D::MONEY_SCALE), D::exact((string) $orders, D::MONEY_SCALE), D::MONEY_SCALE);
    }

    /** Portal orders and quotation acceptances: refused above the limit (the customer sees what is available). */
    public function assertCustomerCanOrder(Partner $customer, string $amount): void
    {
        if ($customer->credit_limit === null) {
            return;
        }
        $exposure = $this->exposure($customer->id, includeDrafts: true);
        if (D::gt(D::add($exposure, $amount, D::MONEY_SCALE), (string) $customer->credit_limit)) {
            $available = D::max('0', D::sub((string) $customer->credit_limit, $exposure, D::MONEY_SCALE));
            throw DomainException::of(ErrorCode::POLICY_VIOLATION,
                "This order would go over your credit limit (available: $available {$customer->default_currency}). Pay open invoices or contact us.",
                ['field' => 'lines', 'credit_limit' => (string) $customer->credit_limit, 'available' => $available]);
        }
    }

    /** Staff confirmation of an order: above the limit only with the override permission and a reason. */
    /** Set or clear (null) a customer's credit limit; the change and its reason are audited. */
    public function setLimit(string $id, UserAccount $actor, ?string $creditLimit = null, ?string $reason = null): Partner
    {
        return DB::transaction(function () use ($id, $actor, $creditLimit, $reason) {
            $customer = Partner::query()->lockForUpdate()->findOrFail($id);
            if (! $customer->hasType(PartnerType::CUSTOMER)) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, "{$customer->name} is not a customer.");
            }
            $limit = $creditLimit === null || $creditLimit === '' ? null : D::exact($creditLimit, D::MONEY_SCALE);
            if ($limit !== null && D::isNegative($limit)) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The credit limit cannot be negative.', ['field' => 'credit_limit']);
            }
            $before = $customer->credit_limit === null ? null : (string) $customer->credit_limit;
            $customer->forceFill(['credit_limit' => $limit])->save();
            $this->audit->record($actor, 'billing.credit.limit_set', 'partners', $customer->id, ['credit_limit' => $before], ['credit_limit' => $limit], $reason);

            return $customer;
        });
    }

    public function assertCanConfirm(SalesOrder $order, UserAccount $actor, ?string $overrideReason): void
    {
        $customer = Partner::query()->find($order->customer_partner_id);
        if ($customer === null || $customer->credit_limit === null) {
            return;
        }
        $after = D::add($this->exposure($customer->id, includeDrafts: false, excludeOrderId: $order->id), (string) $order->total, D::MONEY_SCALE);
        if (D::lte($after, (string) $customer->credit_limit)) {
            return;
        }
        if (trim((string) $overrideReason) === '' || ! $this->access->can($actor, 'billing.credit.override')) {
            throw DomainException::of(ErrorCode::POLICY_VIOLATION,
                "Confirming {$order->document_number} takes {$customer->name} to $after, over the credit limit of {$customer->credit_limit}.",
                ['credit_limit' => (string) $customer->credit_limit, 'exposure_after' => $after, 'field' => 'override_reason'],
                'Collect payment first, or give an override reason (permission billing.credit.override).');
        }
        $order->forceFill(['credit_override_by' => $actor->id, 'credit_override_reason' => $overrideReason])->save();
        $this->audit->record($actor, 'billing.credit.overridden', 'sales_orders', $order->id, null,
            ['credit_limit' => (string) $customer->credit_limit, 'exposure_after' => $after], $overrideReason);
    }
}
