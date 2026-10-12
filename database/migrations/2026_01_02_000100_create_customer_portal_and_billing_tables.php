<?php

use App\Domains\Billing\Enums\InvoiceStatus;
use App\Domains\Billing\Enums\PaymentConfirmationStatus;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Enums\PaymentStatus;
use App\Domains\Catalog\Enums\AttachmentKind;
use App\Domains\CustomerPortal\Enums\AcknowledgementStatus;
use App\Domains\CustomerPortal\Enums\DeliveryIssueType;
use App\Domains\CustomerPortal\Enums\DeliveryOutcome;
use App\Domains\CustomerPortal\Enums\InvitationStatus;
use App\Domains\CustomerPortal\Enums\RegistrationStatus;
use App\Domains\Fulfillment\Enums\SalesChannel;
use App\Domains\IdentityAccess\Enums\AccountType;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Platform\Enums\AttachableType;
use App\Domains\Sales\Enums\QuotationStatus;
use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer Portal (CP-1.1): customer accounts linked to their customer partner, registrations and
 * invitations, quotations, and the minimal billing ledger (invoices, customer payment confirmations
 * with private evidence, finance-recorded payments and allocations), plus delivery acknowledgements.
 * Payments and allocations are append-only; voids and reversals are recorded, never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- Identity: customer accounts ----------------------------------------------------------
        Schema::table('user_accounts', function (Blueprint $t) {
            $t->uuid('customer_partner_id')->nullable();
            $t->foreign('customer_partner_id')->references('id')->on('partners')->restrictOnDelete();
            $t->index('customer_partner_id');
        });
        Pg::enumCheck('user_accounts', 'account_type', AccountType::class);
        // A customer account always belongs to one customer, except while its registration is pending.
        Pg::check('user_accounts', 'chk_user_accounts_customer_partner',
            "(account_type = 'customer') = (customer_partner_id IS NOT NULL) OR (account_type = 'customer' AND account_status IN ('pending_activation', 'deactivated'))");

        // New document types and attachment targets in every enum-checked column.
        foreach (['document_sequences', 'sod_rules', 'approval_policies', 'approval_records', 'quality_checks'] as $table) {
            Pg::enumCheck($table, 'document_type', DocumentType::class);
        }
        Pg::enumCheck('stock_movements', 'source_document_type', DocumentType::class);
        Pg::enumCheck('document_attachments', 'document_type', AttachableType::class);
        Pg::enumCheck('document_attachments', 'kind', AttachmentKind::class);
        Pg::enumCheck('sales_orders', 'channel', SalesChannel::class);

        // ---- Invitations and registrations --------------------------------------------------------
        Schema::create('customer_invitations', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('partner_id');
            $t->string('email', 254);
            $t->string('full_name', 200);
            $t->string('token_hash', 64);
            Pg::enumColumn($t, 'status', default: InvitationStatus::PENDING->value);
            $t->timestampTz('expires_at');
            $t->uuid('invited_by')->nullable();
            $t->uuid('accepted_account_id')->nullable();
            $t->timestampTz('accepted_at')->nullable();
            $t->uuid('revoked_by')->nullable();
            $t->timestampTz('revoked_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            Pg::timestamps($t);
            $t->foreign('partner_id')->references('id')->on('partners')->restrictOnDelete();
            $t->index(['partner_id', 'status']);
        });
        Pg::enumCheck('customer_invitations', 'status', InvitationStatus::class);

        Schema::create('customer_registrations', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('user_account_id')->unique();
            $t->uuid('person_id');
            $t->uuid('invitation_id')->nullable()->unique();
            $t->string('company_name', 200);
            $t->string('tax_id', 80)->nullable();
            $t->string('email', 254);
            $t->string('phone', 32)->nullable();
            $t->jsonb('address')->nullable();
            $t->text('message')->nullable();
            Pg::enumColumn($t, 'status', default: RegistrationStatus::PENDING_VERIFICATION->value);
            $t->string('email_token_hash', 64)->nullable();
            $t->timestampTz('email_token_expires_at')->nullable();
            $t->timestampTz('email_verified_at')->nullable();
            $t->timestampTz('mfa_enrolled_at')->nullable();
            $t->uuid('suggested_partner_id')->nullable();
            $t->uuid('partner_id')->nullable();
            $t->uuid('decided_by')->nullable();
            $t->timestampTz('decided_at')->nullable();
            $t->text('decision_reason')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->unsignedInteger('version')->default(1);
            Pg::timestamps($t);
            $t->foreign('user_account_id')->references('id')->on('user_accounts')->restrictOnDelete();
            $t->foreign('person_id')->references('id')->on('persons')->restrictOnDelete();
            $t->foreign('invitation_id')->references('id')->on('customer_invitations')->restrictOnDelete();
            $t->foreign('suggested_partner_id')->references('id')->on('partners')->restrictOnDelete();
            $t->foreign('partner_id')->references('id')->on('partners')->restrictOnDelete();
            $t->index('status');
        });
        Pg::enumCheck('customer_registrations', 'status', RegistrationStatus::class);

        // ---- Quotations ---------------------------------------------------------------------------
        Schema::create('quotations', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            Pg::documentColumns($t, warehouse: false);
            $t->uuid('warehouse_id')->nullable();
            Pg::enumColumn($t, 'status', default: QuotationStatus::REQUESTED->value);
            $t->uuid('customer_partner_id');
            $t->uuid('requested_by_account_id')->nullable();
            $t->string('currency', 3)->nullable();
            $t->decimal('subtotal', 20, 4)->default(0);
            $t->decimal('total', 20, 4)->default(0);
            $t->date('valid_until')->nullable();
            $t->text('customer_note')->nullable();
            $t->text('staff_note')->nullable();
            $t->text('revision_note')->nullable();
            $t->unsignedInteger('revision')->default(0);
            $t->uuid('sent_by')->nullable();
            $t->timestampTz('sent_at')->nullable();
            $t->uuid('responded_by_account_id')->nullable();
            $t->timestampTz('responded_at')->nullable();
            $t->uuid('sales_order_id')->nullable()->unique();
            $t->string('idempotency_key', 120)->nullable();
            $t->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $t->foreign('customer_partner_id')->references('id')->on('partners')->restrictOnDelete();
            $t->foreign('sales_order_id')->references('id')->on('sales_orders')->restrictOnDelete();
            $t->unique(['customer_partner_id', 'idempotency_key']);
            $t->index(['customer_partner_id', 'status']);
        });
        Pg::enumCheck('quotations', 'status', QuotationStatus::class);
        Pg::documentChecks('quotations');

        Schema::create('quotation_lines', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('quotation_id');
            $t->unsignedInteger('line_no');
            $t->uuid('item_id');
            $t->uuid('uom_id');
            $t->decimal('quantity', 20, 6);
            $t->decimal('unit_price', 20, 4)->nullable();
            $t->decimal('line_total', 20, 4)->default(0);
            $t->text('notes')->nullable();
            Pg::timestamps($t);
            $t->foreign('quotation_id')->references('id')->on('quotations')->restrictOnDelete();
            $t->foreign('item_id')->references('id')->on('items')->restrictOnDelete();
            $t->unique(['quotation_id', 'line_no']);
        });
        Pg::check('quotation_lines', 'chk_quotation_lines_values', 'quantity > 0 AND (unit_price IS NULL OR unit_price >= 0)');

        // ---- Billing ------------------------------------------------------------------------------
        Schema::create('invoices', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            Pg::documentColumns($t, warehouse: false);
            Pg::enumColumn($t, 'status', default: InvoiceStatus::DRAFT->value);
            $t->uuid('customer_partner_id');
            $t->uuid('sales_order_id')->nullable();
            $t->string('currency', 3);
            $t->date('issue_date')->nullable();
            $t->date('due_date')->nullable();
            $t->decimal('subtotal', 20, 4)->default(0);
            $t->decimal('tax_rate', 8, 4)->default(0);
            $t->decimal('tax_amount', 20, 4)->default(0);
            $t->decimal('total', 20, 4)->default(0);
            $t->decimal('amount_paid', 20, 4)->default(0);
            $t->decimal('balance_due', 20, 4)->default(0);
            $t->uuid('issued_by')->nullable();
            $t->timestampTz('issued_at')->nullable();
            $t->uuid('voided_by')->nullable();
            $t->timestampTz('voided_at')->nullable();
            $t->text('void_reason')->nullable();
            $t->foreign('customer_partner_id')->references('id')->on('partners')->restrictOnDelete();
            $t->foreign('sales_order_id')->references('id')->on('sales_orders')->restrictOnDelete();
            $t->index(['customer_partner_id', 'status']);
        });
        Pg::enumCheck('invoices', 'status', InvoiceStatus::class);
        Pg::documentChecks('invoices');
        Pg::check('invoices', 'chk_invoices_amounts', 'total >= 0 AND amount_paid >= 0 AND amount_paid <= total AND balance_due = total - amount_paid AND tax_rate >= 0');
        // One live invoice per sales order.
        DB::statement("CREATE UNIQUE INDEX invoices_sales_order_live_uq ON invoices (sales_order_id) WHERE sales_order_id IS NOT NULL AND status <> 'void'");

        Schema::create('invoice_lines', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('invoice_id');
            $t->unsignedInteger('line_no');
            $t->uuid('item_id')->nullable();
            $t->uuid('sales_order_line_id')->nullable();
            $t->string('description', 300);
            $t->decimal('quantity', 20, 6);
            $t->decimal('unit_price', 20, 4);
            $t->decimal('line_total', 20, 4);
            Pg::timestamps($t);
            $t->foreign('invoice_id')->references('id')->on('invoices')->restrictOnDelete();
            $t->foreign('item_id')->references('id')->on('items')->restrictOnDelete();
            $t->unique(['invoice_id', 'line_no']);
        });
        Pg::check('invoice_lines', 'chk_invoice_lines_values', 'quantity > 0 AND unit_price >= 0');

        Schema::create('payment_confirmations', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->string('document_number', 40)->unique();
            $t->uuid('customer_partner_id');
            $t->uuid('submitted_by_account_id');
            $t->uuid('invoice_id')->nullable();
            $t->uuid('sales_order_id')->nullable();
            $t->decimal('amount', 20, 4);
            $t->string('currency', 3);
            $t->date('paid_on');
            Pg::enumColumn($t, 'method');
            $t->string('transaction_reference', 120);
            $t->string('payer_name', 200)->nullable();
            $t->string('bank_name', 120)->nullable();
            $t->text('customer_note')->nullable();
            Pg::enumColumn($t, 'status', default: PaymentConfirmationStatus::SUBMITTED->value);
            $t->uuid('duplicate_of_id')->nullable();
            $t->text('review_note')->nullable();
            $t->uuid('reviewed_by')->nullable();
            $t->timestampTz('reviewed_at')->nullable();
            $t->uuid('payment_id')->nullable();
            $t->string('idempotency_key', 120)->nullable();
            $t->unsignedInteger('version')->default(1);
            Pg::timestamps($t);
            $t->foreign('customer_partner_id')->references('id')->on('partners')->restrictOnDelete();
            $t->foreign('submitted_by_account_id')->references('id')->on('user_accounts')->restrictOnDelete();
            $t->foreign('invoice_id')->references('id')->on('invoices')->restrictOnDelete();
            $t->foreign('sales_order_id')->references('id')->on('sales_orders')->restrictOnDelete();
            $t->unique(['customer_partner_id', 'idempotency_key']);
            $t->index(['customer_partner_id', 'status']);
            $t->index('status');
        });
        Pg::enumCheck('payment_confirmations', 'status', PaymentConfirmationStatus::class);
        Pg::enumCheck('payment_confirmations', 'method', PaymentMethod::class);
        Pg::check('payment_confirmations', 'chk_payment_confirmations_target', 'amount > 0 AND (invoice_id IS NOT NULL OR sales_order_id IS NOT NULL)');
        DB::statement('CREATE INDEX payment_confirmations_reference_idx ON payment_confirmations (lower(transaction_reference))');

        Schema::create('payments', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->string('document_number', 40)->unique();
            $t->uuid('customer_partner_id');
            $t->decimal('amount', 20, 4);
            $t->string('currency', 3);
            $t->date('received_on');
            Pg::enumColumn($t, 'method');
            $t->string('bank_reference', 120)->nullable();
            $t->uuid('payment_confirmation_id')->nullable()->unique();
            $t->text('notes')->nullable();
            Pg::enumColumn($t, 'status', default: PaymentStatus::POSTED->value);
            $t->uuid('recorded_by')->nullable();
            $t->uuid('voided_by')->nullable();
            $t->timestampTz('voided_at')->nullable();
            $t->text('void_reason')->nullable();
            Pg::timestamps($t);
            $t->foreign('customer_partner_id')->references('id')->on('partners')->restrictOnDelete();
            $t->foreign('payment_confirmation_id')->references('id')->on('payment_confirmations')->restrictOnDelete();
            $t->index(['customer_partner_id', 'status']);
        });
        Pg::enumCheck('payments', 'status', PaymentStatus::class);
        Pg::enumCheck('payments', 'method', PaymentMethod::class);
        Pg::check('payments', 'chk_payments_amount', 'amount > 0');
        // The same bank/mobile transaction can be recorded as a payment only once.
        DB::statement("CREATE UNIQUE INDEX payments_reference_posted_uq ON payments (lower(bank_reference)) WHERE bank_reference IS NOT NULL AND status = 'posted'");
        Schema::table('payment_confirmations', function (Blueprint $t) {
            $t->foreign('payment_id')->references('id')->on('payments')->restrictOnDelete();
        });
        Pg::appendOnly('payments', ['status', 'voided_by', 'voided_at', 'void_reason', 'updated_at']);
        Pg::grantColumnUpdate('payments', ['status', 'voided_by', 'voided_at', 'void_reason', 'updated_at']);

        Schema::create('payment_allocations', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('payment_id');
            $t->uuid('invoice_id');
            $t->decimal('amount', 20, 4);
            $t->uuid('allocated_by')->nullable();
            $t->timestampTz('created_at');
            $t->uuid('reversed_by')->nullable();
            $t->timestampTz('reversed_at')->nullable();
            $t->text('reversal_reason')->nullable();
            $t->foreign('payment_id')->references('id')->on('payments')->restrictOnDelete();
            $t->foreign('invoice_id')->references('id')->on('invoices')->restrictOnDelete();
            $t->index('payment_id');
            $t->index('invoice_id');
        });
        Pg::check('payment_allocations', 'chk_payment_allocations_amount', 'amount > 0');
        Pg::appendOnly('payment_allocations', ['reversed_by', 'reversed_at', 'reversal_reason']);
        Pg::grantColumnUpdate('payment_allocations', ['reversed_by', 'reversed_at', 'reversal_reason']);

        // ---- Delivery acknowledgements --------------------------------------------------------------
        Schema::create('delivery_acknowledgements', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('customer_partner_id');
            $t->uuid('sales_order_id');
            $t->uuid('shipment_id')->nullable();
            $t->uuid('account_id');
            Pg::enumColumn($t, 'outcome');
            Pg::enumColumn($t, 'status', default: AcknowledgementStatus::RECORDED->value);
            $t->text('note')->nullable();
            $t->text('resolution_note')->nullable();
            $t->uuid('resolved_by')->nullable();
            $t->timestampTz('resolved_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            Pg::timestamps($t);
            $t->foreign('customer_partner_id')->references('id')->on('partners')->restrictOnDelete();
            $t->foreign('sales_order_id')->references('id')->on('sales_orders')->restrictOnDelete();
            $t->foreign('shipment_id')->references('id')->on('shipments')->restrictOnDelete();
            $t->foreign('account_id')->references('id')->on('user_accounts')->restrictOnDelete();
            $t->index(['customer_partner_id', 'status']);
        });
        Pg::enumCheck('delivery_acknowledgements', 'outcome', DeliveryOutcome::class);
        Pg::enumCheck('delivery_acknowledgements', 'status', AcknowledgementStatus::class);
        // One acknowledgement per shipment, or per order delivered without a shipment.
        DB::statement('CREATE UNIQUE INDEX delivery_ack_shipment_uq ON delivery_acknowledgements (shipment_id) WHERE shipment_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX delivery_ack_order_uq ON delivery_acknowledgements (sales_order_id) WHERE shipment_id IS NULL');

        Schema::create('delivery_acknowledgement_lines', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('acknowledgement_id');
            $t->uuid('sales_order_line_id');
            $t->uuid('item_id');
            Pg::enumColumn($t, 'issue');
            $t->decimal('quantity', 20, 6)->nullable();
            $t->text('note')->nullable();
            $t->timestampTz('created_at')->nullable();
            $t->foreign('acknowledgement_id')->references('id')->on('delivery_acknowledgements')->restrictOnDelete();
            $t->foreign('sales_order_line_id')->references('id')->on('sales_order_lines')->restrictOnDelete();
        });
        Pg::enumCheck('delivery_acknowledgement_lines', 'issue', DeliveryIssueType::class);
        Pg::check('delivery_acknowledgement_lines', 'chk_dal_quantity', 'quantity IS NULL OR quantity > 0');
    }

    public function down(): void
    {
        foreach (['delivery_acknowledgement_lines', 'delivery_acknowledgements', 'payment_allocations'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('payment_confirmations', fn (Blueprint $t) => $t->dropForeign(['payment_id']));
        foreach (['payments', 'payment_confirmations', 'invoice_lines', 'invoices', 'quotation_lines', 'quotations', 'customer_registrations', 'customer_invitations'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('ALTER TABLE user_accounts DROP CONSTRAINT IF EXISTS chk_user_accounts_customer_partner');
        Schema::table('user_accounts', function (Blueprint $t) {
            $t->dropForeign(['customer_partner_id']);
            $t->dropColumn('customer_partner_id');
        });
    }
};
