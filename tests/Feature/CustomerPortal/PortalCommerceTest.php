<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerPortal;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Models\PaymentConfirmation;
use App\Domains\Billing\Services\PaymentService;
use App\Domains\Catalog\Models\Item;
use App\Domains\Fulfillment\Models\SalesOrder;
use App\Domains\Fulfillment\Services\SalesOrderWorkflow;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Partners\Models\Partner;
use App\Domains\Platform\Enums\PlatformSetupState;
use App\Domains\Platform\Seeds\SeedRunner;
use App\Domains\Platform\Seeds\SodRulesSeed;
use App\Domains\Platform\Services\SetupState;
use App\Domains\Sales\Models\Quotation;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsActors;
use Tests\Support\BuildsInventory;
use Tests\Support\BuildsPortal;
use Tests\TestCase;

/**
 * CP-1.1 sections 5–7: catalogue without internal data, server-priced and retry-safe orders, quotations,
 * delivery receipt, invoices and payment evidence — with customer isolation throughout.
 */
final class PortalCommerceTest extends TestCase
{
    use BuildsActors;
    use BuildsInventory;
    use BuildsPortal;

    private Warehouse $wh;

    private Partner $acme;

    private UserAccount $buyer;

    private UserAccount $otherBuyer;

    private Item $amox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->withHeader('Referer', (string) config('app.url'));   // the portal SPA calls the API from its own origin
        DB::table('platform_setup')->delete();
        DB::table('platform_setup')->insert(['id' => (string) Str::uuid7(), 'deployment_id' => (string) Str::uuid7(), 'state' => PlatformSetupState::COMPLETED->value,
            'completed_steps' => '[]', 'completed_at' => now(), 'locked_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        app(SetupState::class)->refresh();
        app(SeedRunner::class)->run(SodRulesSeed::class);
        $this->wh = $this->warehouse('PW'.Str::upper(Str::random(4)));
        $this->enablePortal($this->wh);
        $this->acme = $this->customerPartner('Acme Pharmacy');
        $this->buyer = $this->customerMember($this->acme);
        $this->otherBuyer = $this->customerMember($this->customerPartner('Other Clinic'));
        $this->amox = $this->onlineItem('25.00');
        $this->receive($this->amox, $this->wh, '100', '9.87');
    }

    private function as(?UserAccount $account): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->flushHeaders();
        $this->withHeader('Referer', (string) config('app.url'));

        return $account === null ? $this : $this->actingAs($account, 'web');
    }

    private function placeOrder(UserAccount $buyer, string $quantity = '4', ?string $key = null): string
    {
        return (string) $this->as($buyer)->withHeaders(['Idempotency-Key' => $key ?? (string) Str::uuid7()])
            ->postJson('/api/v1/portal/orders', ['lines' => [['item_id' => $this->amox->id, 'quantity' => $quantity]]])->assertCreated()->json('data.id');
    }

    public function test_the_catalogue_shows_published_items_without_costs_or_locations(): void
    {
        $hidden = $this->item();
        $hidden->forceFill(['is_sellable' => true, 'is_available_online' => false, 'list_price' => '5'])->save();
        $page = $this->as($this->buyer)->getJson('/api/v1/portal/catalog')->assertOk();
        $ids = array_column($page->json('data'), 'id');
        $this->assertContains($this->amox->id, $ids);
        $this->assertNotContains($hidden->id, $ids);
        $row = collect($page->json('data'))->firstWhere('id', $this->amox->id);
        $this->assertSame(['25.0000', 'ETB', 'in_stock'], [$row['price'], $row['currency'], $row['availability']['band']]);
        $json = json_encode($page->json());
        foreach (['9.87', 'standard_cost', 'average_cost', 'storage_location', 'warehouse', 'lot'] as $secret) {
            $this->assertStringNotContainsString($secret, $json, "catalogue leaks $secret");
        }
        $this->getJson("/api/v1/portal/catalog/items/{$hidden->id}")->assertNotFound();
    }

    public function test_orders_are_priced_by_the_server_isolated_per_customer_and_safe_to_retry(): void
    {
        $this->as($this->buyer)->postJson('/api/v1/portal/orders', ['lines' => [['item_id' => $this->amox->id, 'quantity' => '4']]])->assertStatus(422);  // no request key
        // A customer cannot send a price: the server prices from the list price.
        $this->as($this->buyer)->withHeaders(['Idempotency-Key' => (string) Str::uuid7()])
            ->postJson('/api/v1/portal/orders', ['lines' => [['item_id' => $this->amox->id, 'quantity' => '4', 'unit_price' => '0.01']]])
            ->assertStatus(422)->assertJsonPath('errors.0.property', 'lines.0.unit_price');

        $key = (string) Str::uuid7();
        $id = $this->placeOrder($this->buyer, '4', $key);
        $this->assertSame($id, $this->placeOrder($this->buyer, '4', $key), 'a retried submission returns the first order');
        $this->assertSame(1, SalesOrder::query()->where('customer_partner_id', $this->acme->id)->count());
        $order = SalesOrder::query()->findOrFail($id);
        $this->assertSame(['customer_portal', 'draft', '100.0000'], [$order->channel->value, $order->status->value, (string) $order->total]);

        $this->as($this->otherBuyer)->getJson("/api/v1/portal/orders/$id")->assertNotFound();
        $this->as($this->otherBuyer)->postJson("/api/v1/portal/orders/$id/cancel", [])->assertNotFound();
        $this->assertSame([], $this->as($this->otherBuyer)->getJson('/api/v1/portal/orders')->json('data'));

        $quoteOnly = $this->onlineItem(null);
        $this->as($this->buyer)->withHeaders(['Idempotency-Key' => (string) Str::uuid7()])
            ->postJson('/api/v1/portal/orders', ['lines' => [['item_id' => $quoteOnly->id, 'quantity' => '1']]])->assertStatus(422);
        $this->amox->forceFill(['min_order_qty' => '10'])->save();
        $this->as($this->buyer)->withHeaders(['Idempotency-Key' => (string) Str::uuid7()])
            ->postJson('/api/v1/portal/orders', ['lines' => [['item_id' => $this->amox->id, 'quantity' => '2']]])->assertStatus(422);

        $this->as($this->buyer)->getJson("/api/v1/portal/orders/$id")->assertOk()->assertJsonPath('data.timeline.0.event', 'placed');
        $this->as($this->buyer)->postJson("/api/v1/portal/orders/$id/cancel", ['reason' => 'Ordered twice'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_an_accepted_quotation_becomes_exactly_one_order_while_it_is_valid(): void
    {
        $quote = $this->as($this->buyer)->withHeaders(['Idempotency-Key' => (string) Str::uuid7()])
            ->postJson('/api/v1/portal/quotations', ['lines' => [['item_id' => $this->amox->id, 'quantity' => '50']], 'note' => 'Monthly supply'])
            ->assertCreated()->assertJsonPath('data.status', 'requested')->json('data.id');
        $this->as($this->buyer)->postJson("/api/v1/portal/quotations/$quote/accept")->assertStatus(409);   // not priced yet
        $line = DB::table('quotation_lines')->where('quotation_id', $quote)->value('id');
        $sales = $this->actorWith(['sales.quotation.price']);
        $this->as($sales)->postJson("/api/v1/quotations/$quote/price", ['warehouse_id' => $this->wh->id, 'lines' => [['line_id' => $line, 'unit_price' => '22.50']]])
            ->assertOk();

        $this->as($this->otherBuyer)->postJson("/api/v1/portal/quotations/$quote/accept")->assertNotFound();
        $first = $this->as($this->buyer)->postJson("/api/v1/portal/quotations/$quote/accept")->assertOk()->json('data.order.id');
        $again = $this->as($this->buyer)->postJson("/api/v1/portal/quotations/$quote/accept")->assertOk()->json('data.order.id');
        $this->assertSame($first, $again);
        $this->assertSame(1, SalesOrder::query()->where('external_source', 'quotation')->where('external_order_id', $quote)->count());
        $this->assertSame('22.5000', (string) DB::table('sales_order_lines')->where('sales_order_id', $first)->value('unit_price'));

        $old = $this->as($this->buyer)->withHeaders(['Idempotency-Key' => (string) Str::uuid7()])
            ->postJson('/api/v1/portal/quotations', ['lines' => [['item_id' => $this->amox->id, 'quantity' => '5']]])->json('data.id');
        $this->as($sales)->postJson("/api/v1/quotations/$old/price", ['warehouse_id' => $this->wh->id, 'valid_until' => now()->toDateString(),
            'lines' => [['line_id' => DB::table('quotation_lines')->where('quotation_id', $old)->value('id'), 'unit_price' => '20']]])->assertOk();
        $this->travel(2)->days();
        $this->as($this->buyer)->postJson("/api/v1/portal/quotations/$old/accept")->assertStatus(422)->assertJsonPath('code', 'POLICY_VIOLATION');
        $this->assertSame('expired', Quotation::query()->findOrFail($old)->status->value);
    }

    public function test_delivery_receipt_is_recorded_without_changing_stock_or_the_order(): void
    {
        $id = $this->placeOrder($this->buyer);
        $this->as($this->buyer)->postJson("/api/v1/portal/orders/$id/acknowledge", ['outcome' => 'received_in_full'])->assertStatus(409);   // not delivered
        DB::table('sales_orders')->where('id', $id)->update(['status' => 'delivered']);
        $stock = (string) DB::table('inventory_balances')->sum('quantity_on_hand');
        $line = DB::table('sales_order_lines')->where('sales_order_id', $id)->value('id');

        $this->as($this->otherBuyer)->postJson("/api/v1/portal/orders/$id/acknowledge", ['outcome' => 'received_in_full'])->assertNotFound();
        $this->as($this->buyer)->postJson("/api/v1/portal/orders/$id/acknowledge", ['outcome' => 'received_with_issues'])->assertStatus(422);
        $ack = $this->as($this->buyer)->postJson("/api/v1/portal/orders/$id/acknowledge", ['outcome' => 'received_with_issues',
            'issues' => [['sales_order_line_id' => $line, 'issue' => 'damaged', 'quantity' => '1', 'note' => 'Crushed box']]])->assertCreated()
            ->assertJsonPath('data.status', 'open')->json('data.id');
        $this->as($this->buyer)->postJson("/api/v1/portal/orders/$id/acknowledge", ['outcome' => 'received_in_full'])->assertStatus(409);

        $this->assertSame($stock, (string) DB::table('inventory_balances')->sum('quantity_on_hand'), 'stock never moves');
        $this->assertSame('delivered', DB::table('sales_orders')->where('id', $id)->value('status'));
        $this->assertSame($this->buyer->id, DB::table('delivery_acknowledgements')->where('id', $ack)->value('account_id'));
        $this->as($this->actorWith(['portal.acknowledgement.resolve']))->postJson("/api/v1/delivery-acknowledgements/$ack/resolve", ['comment' => 'Replacement sent'])
            ->assertOk();
        $this->assertSame('resolved', DB::table('delivery_acknowledgements')->where('id', $ack)->value('status'));
    }

    public function test_payment_evidence_never_pays_an_invoice_until_finance_verify_it(): void
    {
        $orderId = $this->placeOrder($this->buyer, '4');
        app(SalesOrderWorkflow::class)->confirm($orderId, $this->actorWith(['fulfillment.sales_order.confirm']));
        $billing = $this->actorWith(['billing.invoice.create', 'billing.invoice.issue']);
        $invoiceId = $this->as($billing)->postJson("/api/v1/sales-orders/$orderId/invoice")->assertOk()->json('data.id');
        $this->as($this->buyer)->getJson("/api/v1/portal/invoices/$invoiceId")->assertNotFound();   // drafts are not shown
        $this->as($billing)->postJson("/api/v1/invoices/$invoiceId/issue", [])->assertOk();
        $this->as($this->buyer)->getJson("/api/v1/portal/invoices/$invoiceId")->assertOk()->assertJsonPath('data.balance_due', '100.0000');
        $this->as($this->otherBuyer)->getJson("/api/v1/portal/invoices/$invoiceId")->assertNotFound();

        $png = $this->png();
        $submit = fn (UserAccount $who, array $files, string $ref = 'FT26285XYZ', string $amount = '60') => $this->as($who)
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid7(), 'Accept' => 'application/json'])
            ->post('/api/v1/portal/payments', ['invoice_id' => $invoiceId, 'amount' => $amount, 'paid_on' => now()->toDateString(), 'method' => 'bank_transfer',
                'transaction_reference' => $ref, 'evidence' => $files]);
        $submit($this->buyer, [UploadedFile::fake()->createWithContent('slip.png', 'not really an image')])->assertStatus(415);
        $submit($this->buyer, [])->assertStatus(422);
        $submit($this->buyer, [$png], 'FT26285XYZ', '150')->assertStatus(422);   // more than the balance
        $submit($this->otherBuyer, [$png])->assertNotFound();
        $confirmationId = $submit($this->buyer, [$png])->assertCreated()->assertJsonPath('data.status', 'submitted')->json('data.id');
        $submit($this->buyer, [$this->png()])->assertStatus(409);   // same reference already under review

        $invoice = Invoice::query()->findOrFail($invoiceId);
        $this->assertSame(['issued', '100.0000', '0.0000'], [$invoice->status->value, (string) $invoice->balance_due, (string) $invoice->amount_paid],
            'a customer upload never changes the invoice');

        $file = DB::table('document_attachments')->where('document_id', $confirmationId)->value('id');
        $this->as($this->buyer)->get("/api/v1/portal/payments/$confirmationId/evidence/$file")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->as($this->otherBuyer)->getJson("/api/v1/portal/payments/$confirmationId/evidence/$file")->assertNotFound();
        $this->as($this->actorWith(['billing.invoice.read']))->getJson("/api/v1/payment-confirmations/$confirmationId/evidence/$file")->assertForbidden();
        $this->as($this->actorWith(['billing.payment_confirmation.read']))->get("/api/v1/payment-confirmations/$confirmationId/evidence/$file")->assertOk();

        $finance = $this->actorWith(['billing.payment.record', 'billing.payment.allocate', 'billing.payment_confirmation.review']);
        $this->as($finance)->postJson("/api/v1/payment-confirmations/$confirmationId/verify", [])->assertOk();
        $this->as($finance)->postJson("/api/v1/payment-confirmations/$confirmationId/verify", [])->assertStatus(409);   // once only
        $invoice->refresh();
        $this->assertSame(['partially_paid', '60.0000', '40.0000'], [$invoice->status->value, (string) $invoice->amount_paid, (string) $invoice->balance_due]);
        $payment = Payment::query()->where('payment_confirmation_id', $confirmationId)->firstOrFail();

        $submit($this->buyer, [$this->png()], 'ft26285xyz', '40')->assertStatus(409);   // already recorded as a payment
        $this->as($finance)->postJson('/api/v1/payments', ['customer_partner_id' => $this->acme->id, 'amount' => '40', 'currency' => 'ETB',
            'received_on' => now()->toDateString(), 'method' => 'bank_transfer', 'bank_reference' => 'FT26285XYZ'])->assertStatus(409);

        app(PaymentService::class)->void($payment->id, $finance, 'Bank reversed the transfer');
        $invoice->refresh();
        $this->assertSame(['issued', '0.0000', '100.0000'], [$invoice->status->value, (string) $invoice->amount_paid, (string) $invoice->balance_due]);
        $this->assertNotNull(DB::table('payment_allocations')->where('payment_id', $payment->id)->value('reversed_at'), 'the allocation is reversed, not deleted');
        $this->assertSame('verified', PaymentConfirmation::query()->findOrFail($confirmationId)->status->value);

        $this->expectException(QueryException::class);
        DB::table('payments')->where('id', $payment->id)->delete();   // append-only ledger
    }

    public function test_staff_screens_list_the_portal_records(): void
    {
        $admin = $this->actorWith(['portal.registration.read', 'portal.invitation.read', 'sales.quotation.read', 'billing.invoice.read',
            'billing.payment_confirmation.read', 'billing.payment.read', 'portal.acknowledgement.read']);
        foreach (['customer-registrations', 'customer-invitations', 'quotations', 'invoices', 'payment-confirmations', 'payments', 'delivery-acknowledgements'] as $resource) {
            $this->as($admin)->get("/r/$resource")->assertOk();
            $this->as($admin)->getJson("/api/v1/$resource")->assertOk();
        }
    }

    private function png(): UploadedFile
    {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($image);

        return UploadedFile::fake()->createWithContent('bank-slip.png', (string) ob_get_clean());
    }
}
