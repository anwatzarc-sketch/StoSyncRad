<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment;

use App\Domains\IdentityAccess\Enums\PermissionScopeKind as K;
use App\Domains\IdentityAccess\Enums\PermissionSensitivity as S;
use App\Domains\IdentityAccess\Permissions\PermissionDefinition as P;
use App\Domains\IdentityAccess\Permissions\ProvidesPermissions;

final class Permissions implements ProvidesPermissions
{
    public static function module(): string
    {
        return 'fulfillment';
    }

    public static function moduleLabel(): string
    {
        return 'Requests, Issues & Sales Fulfillment';
    }

    public static function definitions(): array
    {
        $ecom = 'feature.ecommerce_fulfillment';
        // Shared by the retail, e-commerce and customer portal channels: the channel's feature is enforced
        // per order by SalesOrderWorkflow::assertChannelEnabled (deliveries and every shipment action
        // included), so these are not tied to a single flag.
        $anySales = null;

        return [
            ...P::crud('fulfillment.stock_request', 'stock requests', ['read', 'create', 'update', 'submit', 'approve', 'reject', 'cancel'], K::WAREHOUSE, [
                'approve' => ['sensitivity' => S::ELEVATED], 'reject' => ['sensitivity' => S::ELEVATED],
            ]),
            P::own('fulfillment.stock_request.read_own', 'View own stock requests'),
            P::own('fulfillment.stock_request.create_own', 'Create own stock requests'),
            P::own('fulfillment.stock_request.cancel_own', 'Cancel own stock requests'),
            ...P::crud('fulfillment.pick_list', 'pick lists', ['read', 'assign', 'pick', 'complete'], K::WAREHOUSE),
            P::warehouse('fulfillment.pick_wave.manage', 'Manage pick waves', '', S::NORMAL, ['fulfillment.pick_list.read']),
            ...P::crud('fulfillment.stock_issue', 'stock issues', ['read', 'create', 'update', 'submit', 'approve', 'post', 'cancel'], K::WAREHOUSE, [
                'approve' => ['sensitivity' => S::ELEVATED], 'post' => ['sensitivity' => S::ELEVATED],
            ]),
            P::warehouse('fulfillment.stock_issue.direct_issue', 'Issue stock without a request', '', S::ELEVATED, ['fulfillment.stock_issue.create']),
            ...P::crud('fulfillment.internal_return', 'internal returns', ['read', 'create', 'inspect', 'approve', 'post'], K::WAREHOUSE, [
                'post' => ['sensitivity' => S::ELEVATED],
            ]),
            P::warehouse('fulfillment.sales_order.read', 'View sales orders', '', S::NORMAL, [], $anySales),
            P::warehouse('fulfillment.sales_order.create', 'Create sales orders', '', S::NORMAL, ['fulfillment.sales_order.read'], $anySales),
            P::warehouse('fulfillment.sales_order.update', 'Update sales orders', '', S::NORMAL, ['fulfillment.sales_order.read'], $anySales),
            P::warehouse('fulfillment.sales_order.confirm', 'Confirm sales orders', '', S::NORMAL, ['fulfillment.sales_order.read'], $anySales),
            P::warehouse('fulfillment.sales_order.cancel', 'Cancel sales orders', '', S::NORMAL, ['fulfillment.sales_order.read'], $anySales),
            P::warehouse('fulfillment.sales_order.deliver', 'Post sales deliveries', '', S::ELEVATED, ['fulfillment.sales_order.read'], $anySales),
            P::warehouse('fulfillment.sales_order.intake_api', 'Submit e-commerce orders via API', '', S::NORMAL, [], $ecom),
            P::warehouse('fulfillment.shipment.read', 'View shipments', '', S::NORMAL, [], $anySales),
            P::warehouse('fulfillment.shipment.pack', 'Pack shipments', '', S::NORMAL, ['fulfillment.shipment.read'], $anySales),
            P::warehouse('fulfillment.shipment.handover', 'Hand shipments to carriers', '', S::ELEVATED, ['fulfillment.shipment.read'], $anySales),
            P::warehouse('fulfillment.shipment.update_status', 'Update shipment status', '', S::NORMAL, ['fulfillment.shipment.read'], $anySales),
            P::warehouse('fulfillment.customer_return.read', 'View customer returns', '', S::NORMAL, [], 'feature.customer_returns'),
            P::warehouse('fulfillment.customer_return.authorize', 'Authorize customer returns (RMA)', '', S::NORMAL, ['fulfillment.customer_return.read'], 'feature.customer_returns'),
            P::warehouse('fulfillment.customer_return.receive', 'Receive customer returns', '', S::NORMAL, ['fulfillment.customer_return.read'], 'feature.customer_returns'),
            P::warehouse('fulfillment.customer_return.inspect', 'Inspect customer returns', '', S::NORMAL, ['fulfillment.customer_return.read'], 'feature.customer_returns'),
            P::warehouse('fulfillment.customer_return.post', 'Post customer returns', '', S::ELEVATED, ['fulfillment.customer_return.read'], 'feature.customer_returns'),
            P::warehouse('fulfillment.production.issue', 'Issue to production', '', S::ELEVATED, [], 'feature.production_store'),
            P::warehouse('fulfillment.production.receive', 'Receive from production', '', S::ELEVATED, [], 'feature.production_store'),
        ];
    }
}
