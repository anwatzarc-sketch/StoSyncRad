<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerPortal;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Services\InvoiceWorkflow;
use App\Domains\Billing\Services\TaxRates;
use App\Domains\Catalog\Models\Item;
use App\Domains\CustomerPortal\Models\CustomerInvitation;
use App\Domains\Fulfillment\Models\SalesOrder;
use App\Domains\Fulfillment\Services\SalesOrderWorkflow;
use App\Domains\Fulfillment\Services\ShipmentWorkflow;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Partners\Models\Partner;
use App\Domains\Platform\Config\PlatformConfig;
use App\Domains\Platform\Enums\PlatformSetupState;
use App\Domains\Platform\Seeds\SeedRunner;
use App\Domains\Platform\Seeds\SodRulesSeed;
use App\Domains\Platform\Services\SetupState;
use App\Domains\Sales\Models\Quotation;
use App\Support\Exceptions\DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsActors;
use Tests\Support\BuildsInventory;
use Tests\Support\BuildsPortal;
use Tests\TestCase;

/**
 * Portal round 2: customer administrators and colleagues, credit limits, tax rates per line, partial and
 * per-shipment invoicing, nightly quotation expiry, configurable e-mail delivery and product photos.
 */
final class PortalTeamAndBillingTest extends TestCase
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
        $this->withHeader('Referer', (string) config('app.url'));
        DB::table('platform_setup')->delete();
        DB::table('platform_setup')->insert(['id' => (string) Str::uuid7(), 'deployment_id' => (string) Str::uuid7(), 'state' => PlatformSetupState::COMPLETED->value,
            'completed_steps' => '[]', 'completed_at' => now(), 'locked_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        app(SetupState::class)->refresh();
        app(SeedRunner::class)->run(SodRulesSeed::class);
        $this->wh = $this->warehouse('PT'.Str::upper(Str::random(4)));
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

    /** @param  array<int, array{0: Item, 1: string}>  $lines */
    private function placeOrder(UserAccount $buyer, array $lines): TestResponse
    {
        return $this->as($buyer)->withHeaders(['Idempotency-Key' => (string) Str::uuid7()])
            ->postJson('/api/v1/portal/orders', ['lines' => array_map(fn (array $l) => ['item_id' => $l[0]->id, 'quantity' => $l[1]], $lines)]);
    }

    public function test_customer_administrators_manage_their_own_colleagues_only(): void
    {
        $colleague = $this->customerMember($this->acme);
        $team = $this->as($colleague)->getJson('/api/v1/portal/team')->assertOk();
        $this->assertFalse($team->json('data.can_manage'));
        $this->assertSame([true, false], array_column($team->json('data.members'), 'administrator'), 'the first member is the administrator');
        $this->as($colleague)->postJson('/api/v1/portal/team/invitations', ['email' => 'x@acme.example', 'full_name' => 'X'])->assertForbidden();

        // An administrator invites into their own customer only; a customer id cannot be supplied.
        $this->as($this->buyer)->postJson('/api/v1/portal/team/invitations', ['email' => 'y@acme.example', 'full_name' => 'Y',
            'partner_id' => $this->otherBuyer->customer_partner_id])->assertStatus(422);
        $inv = $this->as($this->buyer)->postJson('/api/v1/portal/team/invitations', ['email' => 'Lead@Acme.example', 'full_name' => 'Lead Buyer', 'as_admin' => true])
            ->assertCreated()->json('data.id');
        $invitation = CustomerInvitation::query()->findOrFail($inv);
        $this->assertSame([$this->acme->id, 'lead@acme.example', true, $this->buyer->id], [$invitation->partner_id, $invitation->email, $invitation->as_admin, $invitation->invited_by]);
        $this->as($this->otherBuyer)->postJson("/api/v1/portal/team/invitations/$inv/revoke")->assertNotFound();
        $this->as($this->buyer)->getJson('/api/v1/portal/team')->assertJsonPath('data.invitations.0.email', 'lead@acme.example');
        $this->as($this->buyer)->postJson("/api/v1/portal/team/invitations/$inv/revoke")->assertOk();

        // The company always keeps an administrator, and nobody removes themselves.
        $this->as($this->buyer)->postJson("/api/v1/portal/team/members/{$this->buyer->id}/role", ['administrator' => false])->assertStatus(422);
        $this->as($this->buyer)->postJson("/api/v1/portal/team/members/{$this->buyer->id}/deactivate")->assertStatus(422);
        $this->as($this->otherBuyer)->postJson("/api/v1/portal/team/members/{$colleague->id}/deactivate")->assertNotFound();
        $this->as($this->buyer)->postJson("/api/v1/portal/team/members/{$colleague->id}/role", ['administrator' => true])->assertOk()->assertJsonPath('data.administrator', true);
        $this->assertContains('customer.member.manage', $this->as($colleague)->getJson('/api/v1/portal/me')->json('data.permissions'));

        $this->as($colleague)->postJson("/api/v1/portal/team/members/{$this->buyer->id}/deactivate")->assertOk()->assertJsonPath('data.status', 'deactivated');
        $this->as(null)->postJson('/api/v1/portal/public/login', ['identifier' => $this->buyer->email, 'password' => self::CUSTOMER_PASSWORD])->assertUnauthorized();
        $this->as($colleague)->postJson("/api/v1/portal/team/members/{$this->buyer->id}/reactivate")->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'portal.membership.deactivated')->where('subject_id', $this->buyer->id)->count());
    }

    public function test_the_user_limit_counts_members_and_open_invitations(): void
    {
        $this->setConfig('portal.team.max_members', 2);
        app(PlatformConfig::class)->forget();
        $this->as($this->buyer)->postJson('/api/v1/portal/team/invitations', ['email' => 'one@acme.example', 'full_name' => 'One'])->assertCreated();
        $this->as($this->buyer)->postJson('/api/v1/portal/team/invitations', ['email' => 'two@acme.example', 'full_name' => 'Two'])
            ->assertStatus(422)->assertJsonPath('code', 'POLICY_VIOLATION');
    }

    public function test_a_credit_limit_blocks_portal_orders_and_needs_an_override_to_confirm(): void
    {
        $this->as($this->actorWith(['partners.partner.read']))->postJson("/api/v1/partners/{$this->acme->id}/credit-limit", ['credit_limit' => '150'])->assertForbidden();
        $this->as($this->actorWith(['billing.credit.manage']))->postJson("/api/v1/partners/{$this->acme->id}/credit-limit", ['credit_limit' => '150', 'reason' => 'Agreed terms'])
            ->assertOk();
        $first = $this->placeOrder($this->buyer, [[$this->amox, '4']])->assertCreated()->json('data.id');   // 100 of 150
        $this->placeOrder($this->buyer, [[$this->amox, '4']])->assertStatus(422)->assertJsonPath('code', 'POLICY_VIOLATION');
        $this->as($this->buyer)->getJson('/api/v1/portal/dashboard')->assertJsonPath('data.credit.limit', '150.0000')->assertJsonPath('data.credit.available', '50.0000');
        $this->placeOrder($this->otherBuyer, [[$this->amox, '40']])->assertCreated();   // no limit for the other customer

        $this->acme->forceFill(['credit_limit' => '50'])->save();
        $confirm = $this->actorWith(['fulfillment.sales_order.confirm']);
        $this->expectDomainError('POLICY_VIOLATION', fn () => app(SalesOrderWorkflow::class)->confirm($first, $confirm));
        $this->expectDomainError('POLICY_VIOLATION', fn () => app(SalesOrderWorkflow::class)->confirm($first, $confirm, null, 'Customer is good for it'));
        $credit = $this->actorWith(['fulfillment.sales_order.confirm', 'billing.credit.override']);
        app(SalesOrderWorkflow::class)->confirm($first, $credit, null, 'Paid in cash at the counter');
        $order = SalesOrder::query()->findOrFail($first);
        $this->assertSame(['reserved', $credit->id, 'Paid in cash at the counter'], [$order->status->value, $order->credit_override_by, $order->credit_override_reason]);
    }

    public function test_each_invoice_line_is_taxed_at_its_own_rate(): void
    {
        $taxAdmin = $this->actorWith(['billing.tax.read', 'billing.tax.manage']);
        $this->as($taxAdmin)->postJson('/api/v1/tax-rates', ['code' => 'VAT15', 'name' => 'VAT 15%', 'rate' => '15', 'is_default' => true])->assertCreated();
        $exempt = $this->as($taxAdmin)->postJson('/api/v1/tax-rates', ['code' => 'EXEMPT', 'name' => 'Exempt', 'rate' => '0'])->assertCreated()->json('data.id');
        $this->amox->forceFill(['tax_rate_id' => $exempt])->save();
        $gloves = $this->onlineItem('10.00', ['name' => 'Examination gloves']);
        $this->receive($gloves, $this->wh, '50', '4');

        $orderId = $this->placeOrder($this->buyer, [[$this->amox, '4'], [$gloves, '2']])->assertCreated()->json('data.id');
        app(SalesOrderWorkflow::class)->confirm($orderId, $this->actorWith(['fulfillment.sales_order.confirm']));
        $invoice = app(InvoiceWorkflow::class)->fromSalesOrder($orderId, $this->actorWith(['billing.invoice.create']));
        $this->assertSame(['120.0000', '3.0000', '123.0000', '2.5000'], [(string) $invoice->subtotal, (string) $invoice->tax_amount, (string) $invoice->total, (string) $invoice->tax_rate]);
        $this->assertSame([['0.0000', '0.0000'], ['15.0000', '3.0000']], $invoice->lines()->get()->map(fn ($l) => [(string) $l->tax_rate, (string) $l->tax_amount])->all());

        // Only one default rate at a time.
        $this->as($taxAdmin)->postJson('/api/v1/tax-rates', ['code' => 'VAT10', 'name' => 'VAT 10%', 'rate' => '10', 'is_default' => true])->assertCreated();
        $this->assertSame(['VAT10'], DB::table('tax_rates')->where('is_default', true)->pluck('code')->all());
        $this->assertSame('10.0000', app(TaxRates::class)->forItem($gloves->id));
    }

    public function test_orders_are_invoiced_in_parts_and_never_beyond_what_was_ordered(): void
    {
        $this->wh->forceFill(['capabilities' => array_values(array_unique([...$this->wh->capabilities, 'pack_and_ship']))])->save();
        $orderId = $this->placeOrder($this->buyer, [[$this->amox, '4']])->assertCreated()->json('data.id');
        $staff = $this->superAdmin();
        app(SalesOrderWorkflow::class)->confirm($orderId, $staff);
        $invoices = app(InvoiceWorkflow::class);
        $this->expectDomainError('VALIDATION_FAILED', fn () => $invoices->fromSalesOrder($orderId, $staff, 'delivered'));   // nothing delivered yet

        $line = DB::table('sales_order_lines')->where('sales_order_id', $orderId)->value('id');
        $shipments = app(ShipmentWorkflow::class);
        $shipment = $shipments->pack($orderId, $staff, ['lines' => [['order_line_id' => $line, 'storage_location_id' => $this->wh->default_storage_location_id, 'quantity' => '3']]]);
        $this->expectDomainError('INVALID_STATE_TRANSITION', fn () => $invoices->fromShipment($shipment->id, $staff));   // not handed over yet
        $shipments->handover($shipment->id, $staff, (string) Str::uuid7());

        $byShipment = $invoices->fromShipment($shipment->id, $staff);
        $this->assertSame(['3.000000', '75.0000', $shipment->id], [(string) $byShipment->lines()->value('quantity'), (string) $byShipment->subtotal, $byShipment->shipment_id]);
        $this->expectDomainError('DUPLICATE_RESOURCE', fn () => $invoices->fromShipment($shipment->id, $staff));
        $this->expectDomainError('VALIDATION_FAILED', fn () => $invoices->fromSalesOrder($orderId, $staff, 'delivered'));   // delivered 3, invoiced 3

        $rest = $invoices->fromSalesOrder($orderId, $staff, 'ordered');
        $this->assertSame('1.000000', (string) $rest->lines()->value('quantity'));
        $this->expectDomainError('VALIDATION_FAILED', fn () => $invoices->fromSalesOrder($orderId, $staff, 'ordered'));
        $this->expectDomainError('QUANTITY_EXCEEDS_OUTSTANDING', fn () => $invoices->save(['lines' => [['description' => 'More', 'quantity' => '2', 'unit_price' => '25',
            'item_id' => $this->amox->id, 'sales_order_line_id' => $line]]], $staff, $rest->id));

        $invoices->void($rest->id, $staff, 'Billed on the next shipment instead');
        $this->assertSame('1.000000', (string) $invoices->fromSalesOrder($orderId, $staff)->lines()->value('quantity'), 'a void invoice frees its quantities');
        $this->assertSame(3, Invoice::query()->where('sales_order_id', $orderId)->count());
    }

    public function test_sent_quotations_expire_overnight_and_can_no_longer_be_accepted(): void
    {
        $quote = $this->as($this->buyer)->withHeaders(['Idempotency-Key' => (string) Str::uuid7()])
            ->postJson('/api/v1/portal/quotations', ['lines' => [['item_id' => $this->amox->id, 'quantity' => '5']]])->assertCreated()->json('data.id');
        $line = DB::table('quotation_lines')->where('quotation_id', $quote)->value('id');
        $this->as($this->actorWith(['sales.quotation.price']))->postJson("/api/v1/quotations/$quote/price", ['warehouse_id' => $this->wh->id,
            'lines' => [['line_id' => $line, 'unit_price' => '22.50']]])->assertOk();
        $fresh = Quotation::query()->findOrFail($quote);
        DB::table('quotations')->where('id', $quote)->update(['valid_until' => now()->subDay()->toDateString()]);

        $this->assertSame(0, Artisan::call('sales:expire-quotations'));
        $this->assertSame(['sent', 'expired'], [$fresh->status->value, Quotation::query()->findOrFail($quote)->status->value]);
        $this->as($this->buyer)->postJson("/api/v1/portal/quotations/$quote/accept")->assertStatus(409);
        $this->assertSame(0, SalesOrder::query()->where('customer_partner_id', $this->acme->id)->count());
    }

    public function test_mail_delivery_settings_can_be_tested_by_administrators(): void
    {
        $admin = $this->actorWith(['platform.config.messaging_manage']);
        $this->setConfig('messaging.mail.transport', 'log');
        app(PlatformConfig::class)->forget();
        $this->as($admin)->postJson('/api/v1/admin/mail/test', ['to' => 'ops@example.test'])->assertOk()->assertJsonPath('data.transport', 'log');
        $this->as($this->actorWith(['catalog.item.read']))->postJson('/api/v1/admin/mail/test', ['to' => 'ops@example.test'])->assertForbidden();

        // A server that refuses the connection is reported, not swallowed.
        foreach (['messaging.mail.transport' => 'smtp', 'messaging.mail.smtp.host' => '127.0.0.1', 'messaging.mail.smtp.port' => 1,
            'messaging.mail.smtp.username' => 'shop@example.test'] as $key => $value) {
            $this->setConfig($key, $value);
        }
        app(PlatformConfig::class)->forget();
        $this->as($admin)->postJson('/api/v1/admin/mail/test', ['to' => 'ops@example.test'])->assertStatus(422)->assertJsonPath('code', 'POLICY_VIOLATION');
    }

    public function test_product_photos_are_checked_stored_privately_and_shown_to_customers(): void
    {
        Storage::fake('private');
        $editor = $this->actorWith(['catalog.item.read', 'catalog.item.update']);
        $url = "/api/v1/items/{$this->amox->id}/images";
        $upload = fn (UploadedFile $file, UserAccount $who) => $this->as($who)->withHeaders(['Accept' => 'application/json'])->post($url, ['image' => $file]);

        $upload($this->png(), $this->actorWith(['catalog.item.read']))->assertForbidden();
        $upload(UploadedFile::fake()->createWithContent('photo.png', '<svg onload="alert(1)"></svg>'), $editor)->assertStatus(415);
        $first = $upload($this->png(), $editor)->assertCreated()->assertJsonPath('data.is_primary', true)->json('data.id');
        $second = $upload($this->png(), $editor)->assertCreated()->assertJsonPath('data.is_primary', false)->json('data.id');
        $this->assertStringStartsWith("item-images/{$this->amox->id}/", (string) DB::table('item_images')->where('id', $first)->value('path'));

        $this->as($editor)->postJson("$url/$second/primary")->assertOk()->assertJsonPath('data.0.id', $second);
        $this->as($editor)->get("$url/$second")->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->as($this->buyer)->get("/api/v1/portal/catalog/items/{$this->amox->id}/image")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertNotNull(collect($this->as($this->buyer)->getJson('/api/v1/portal/catalog')->json('data'))->firstWhere('id', $this->amox->id)['image_url']);

        $this->as($editor)->deleteJson("$url/$second")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_primary', true);
        $other = $this->onlineItem('5.00');
        $this->as($editor)->deleteJson("/api/v1/items/{$other->id}/images/$first")->assertNotFound();   // image of another item
    }

    private function expectDomainError(string $code, callable $fn): void
    {
        try {
            $fn();
        } catch (DomainException $e) {
            $this->assertSame($code, $e->errorCode->value, $e->getMessage());

            return;
        }
        $this->fail("Expected $code");
    }

    private function png(): UploadedFile
    {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($image);

        return UploadedFile::fake()->createWithContent('photo.png', (string) ob_get_clean());
    }
}
