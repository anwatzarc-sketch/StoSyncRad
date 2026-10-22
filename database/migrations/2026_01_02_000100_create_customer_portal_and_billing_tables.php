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
use App\Support\Database\Ddl;
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
        Ddl::enumCheck('user_accounts', 'account_type', AccountType::class);
        // A customer account always belongs to one customer, except while its registration is pending.
        Ddl::check('user_accounts', 'chk_user_accounts_customer_partner',
            "(account_type = 'customer') = (customer_partner_id IS NOT NULL) OR (account_type = 'customer' AND account_status IN ('pending_activation', 'deactivated'))");

        // New document types and attachment targets in every enum-checked column.
        foreach (['document_sequences', 'sod_rules', 'approval_policies', 'approval_records', 'quality_checks'] as $table) {
            Ddl::enumCheck($table, 'document_type', DocumentType::class);
        }
        Ddl::enumCheck('stock_movements', 'source_document_type', DocumentType::class);
        Ddl::enumCheck('document_attachments', 'document_type', AttachableType::class);
        Ddl::enumCheck('document_attachments', 'kind', AttachmentKind::class);
        Ddl::enumCheck('sales_orders', 'channel', SalesChannel::class);

        // ---- Invitations and registrations --------------------------------------------------------
        Schema::create('customer_invitations', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('partner_id');
            $t->string('email', 254);
            $t->string('full_name', 200);
            $t->string('token_hash', 64);
            Ddl::enumColumn($t, 'status', default: InvitationStatus::PENDING->value);
            $t->dateTime('expires_at', 6);
            $t->uuid('invited_by')->nullable();
            $t->uuid('accepted_account_id')->nullable();
            $t->dateTime('accepted_at', 6)->nullable();
            $t->uuid('revoked_by')->nullable();
            $t->dateTime('revoked_at', 6)->nullable();
            $t->integer('version')->default(1);
            Ddl::timestamps($t);
            $t->foreign('partner_id')->references('id')->on('partners')->restrictOnDelete();
            $t->index(['partner_id', 'status']);
        });
        Ddl::enumCheck('customer_invitations', 'status', InvitationStatus::class);

        Schema::create('customer_registrations', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('user_account_id')->unique();
            $t->uuid('person_id');
            $t->uuid('invitation_id')->nullable()->unique();
            $t->string('company_name', 200);
            $t->string('tax_id', 80)->nullable();
            $t->string('email', 254);
            $t->string('phone', 32)->nullable();
            $t->json('address')->nullable();
            $t->text('message')->nullable();
            Ddl::enumColumn($t, 'status', default: RegistrationStatus::PENDING_VERIFICATION->value);
            $t->string('email_token_hash', 64)->nullable();
            $t->dateTime('email_token_expires_at', 6)->nullable();
            $t->dateTime('email_verified_at', 6)->nullable();
            $t->dateTime('mfa_enrolled_at', 6)->nullable();
            $t->uuid('suggested_partner_id')->nullable();
            $t->uuid('partner_id')->nullable();
            $t->uuid('decided_by')->nullable();
            $t->dateTime('decided_at', 6)->nullable();
            $t->text('decision_reason')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->integer('version')->default(1);
            Ddl::timestamps($t);
            $t->foreign('user_account_id')->references('id')->on('user_accounts')->restrictOnDelete();
            $t->foreign('person_id')->references('id')->on('persons')->restrictOnDelete();
            $t->foreign('invitation_id')->references('id')->on('customer_invitations')->restrictOnDelete();
            $t->foreign('suggested_partner_id')->references('id')->on('partners')->restrictOnDelete();
            $t->foreign('partner_id')->references('id')->on('partners')->restrictOnDelete();
            $t->index('status');
        });
        Ddl::enumCheck('customer_registrations', 'status', RegistrationStatus::class);

        // ---- Quotations ---------------------------------------------------------------------------
        Schema::create('quotations', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            Ddl::documentColumns($t, warehouse: false);
            $t->uuid('warehouse_id')->nullable();
            Ddl::enumColumn($t, 'status', default: QuotationStatus::REQUESTED->value);
            $t->uuid('customer_partner_id');
            $t->uuid('requested_by_account_id')->nullable();
            $t->string('currency', 3)->nullable();
            $t->decimal('subtotal', 20, 4)->default(0);
            $t->decimal('total', 20, 4)->default(0);
            $t->date('valid_until')->nullable();
            $t->text('customer_note')->nullable();
            $t->text('staff_note')->nullable();
            $t->text('revision_note')->nullable();
            $t->integer('revision')->default(0);
            $t->uuid('sent_by')->nullable();
            $t->dateTime('sent_at', 6)->nullable();
            $t->uuid('responded_by_account_id')->nullable();
            $t->dateTime('responded_at', 6)->nullable();
            $t->uuid('sales_order_id')->nullable()->unique();
            $t->string('idempotency_key', 120)->nullable();
            $t->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $t->foreign('customer_partner_id')->references('id')->on('partners')->restrictOnDelete();
            $t->foreign('sales_order_id')->references('id')->on('sales_orders')->restrictOnDelete();
            $t->unique(['customer_partner_id', 'idempotency_key']);
            $t->index(['customer_partner_id', 'status']);
        });
        Ddl::enumCheck('quotations', 'status', QuotationStatus::class);
        Ddl::documentChecks('quotations');

        Schema::create('quotation_lines', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('quotation_id');
            $t->integer('line_no');
            $t->uuid('item_id');
            $t->uuid('uom_id');
            $t->decimal('quantity', 20, 6);
            $t->decimal('unit_price', 20, 4)->nullable();
            $t->decimal('line_total', 20, 4)->default(0);
            $t->text('notes')->nullable();
            Ddl::timestamps($t);
            $t->foreign('quotation_id')->references('id')->on('quotations')->restrictOnDelete();
            $t->foreign('item_id')->references('id')->on('items')->restrictOnDelete();
            $t->unique(['quotation_id', 'line_no']);
        });
        Ddl::check('quotation_lines', 'chk_quotation_lines_values', 'quantity > 0 AND (unit_price IS NULL OR unit_price >= 0)');

        // ---- Billing ------------------------------------------------------------------------------
        Schema::create('invoices', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            Ddl::documentColumns($t, warehouse: false);
            Ddl::enumColumn($t, 'status', default: InvoiceStatus::DRAFT->value);
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
            $t->dateTime('issued_at', 6)->nullable();
            $t->uuid('voided_by')->nullable();
            $t->dateTime('voided_at', 6)->nullable();
            $t->text('void_reason')->nullable();
            $t->foreign('customer_partner_id')->references('id')->on('partners')->restrictOnDelete();
            $t->foreign('sales_order_id')->references('id')->on('sales_orders')->restrictOnDelete();
            $t->index(['customer_partner_id', 'status']);
        });
        Ddl::enumCheck('invoices', 'status', InvoiceStatus::class);
        Ddl::documentChecks('invoices');
        Ddl::check('invoices', 'chk_invoices_amounts', 'total >= 0 AND amount_paid >= 0 AND amount_paid <= total AND balance_due = total - amount_paid AND tax_rate >= 0');
        // One live invoice per sales order.
        Ddl::uniqueExpression('invoices', 'invoices_sales_order_live_uq', "CASE WHEN sales_order_id IS NOT NULL AND status <> 'void' THEN sales_order_id END");

        Schema::create('invoice_lines', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('invoice_id');
            $t->integer('line_no');
            $t->uuid('item_id')->nullable();
            $t->uuid('sales_order_line_id')->nullable();
            $t->string('description', 300);
            $t->decimal('quantity', 20, 6);
            $t->decimal('unit_price', 20, 4);
            $t->decimal('line_total', 20, 4);
            Ddl::timestamps($t);
            $t->foreign('invoice_id')->references('id')->on('invoices')->restrictOnDelete();
            $t->foreign('item_id')->references('id')->on('items')->restrictOnDelete();
            $t->unique(['invoice_id', 'line_no']);
        });
        Ddl::check('invoice_lines', 'chk_invoice_lines_values', 'quantity > 0 AND unit_price >= 0');

        Schema::create('payment_confirmations', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->string('document_number', 40)->unique();
            $t->uuid('customer_partner_id');
            $t->uuid('submitted_by_account_id');
            $t->uuid('invoice_id')->nullable();
            $t->uuid('sales_order_id')->nullable();
            $t->decimal('amount', 20, 4);
            $t->string('currency', 3);
            $t->date('paid_on');
            Ddl::enumColumn($t, 'method');
            $t->string('transaction_reference', 120);
            $t->string('payer_name', 200)->nullable();
            $t->string('bank_name', 120)->nullable();
            $t->text('customer_note')->nullable();
            Ddl::enumColumn($t, 'status', default: PaymentConfirmationStatus::SUBMITTED->value);
            $t->uuid('duplicate_of_id')->nullable();
            $t->text('review_note')->nullable();
            $t->uuid('reviewed_by')->nullable();
            $t->dateTime('reviewed_at', 6)->nullable();
            $t->uuid('payment_id')->nullable();
            $t->string('idempotency_key', 120)->nullable();
            $t->integer('version')->default(1);
            Ddl::timestamps($t);
            $t->foreign('customer_partner_id')->references('id')->on('partners')->restrictOnDelete();
            $t->foreign('submitted_by_account_id')->references('id')->on('user_accounts')->restrictOnDelete();
            $t->foreign('invoice_id')->references('id')->on('invoices')->restrictOnDelete();
            $t->foreign('sales_order_id')->references('id')->on('sales_orders')->restrictOnDelete();
            $t->unique(['customer_partner_id', 'idempotency_key']);
            $t->index(['customer_partner_id', 'status']);
            $t->index('status');
        });
        Ddl::enumCheck('payment_confirmations', 'status', PaymentConfirmationStatus::class);
        Ddl::enumCheck('payment_confirmations', 'method', PaymentMethod::class);
        Ddl::check('payment_confirmations', 'chk_payment_confirmations_target', 'amount > 0 AND (invoice_id IS NOT NULL OR sales_order_id IS NOT NULL)');
        DB::statement('CREATE INDEX payment_confirmations_reference_idx ON payment_confirmations ((lower(transaction_reference)))');

        Schema::create('payments', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->string('document_number', 40)->unique();
            $t->uuid('customer_partner_id');
            $t->decimal('amount', 20, 4);
            $t->string('currency', 3);
            $t->date('received_on');
            Ddl::enumColumn($t, 'method');
            $t->string('bank_reference', 120)->nullable();
            $t->uuid('payment_confirmation_id')->nullable()->unique();
            $t->text('notes')->nullable();
            Ddl::enumColumn($t, 'status', default: PaymentStatus::POSTED->value);
            $t->uuid('recorded_by')->nullable();
            $t->uuid('voided_by')->nullable();
            $t->dateTime('voided_at', 6)->nullable();
            $t->text('void_reason')->nullable();
            Ddl::timestamps($t);
            $t->foreign('customer_partner_id')->references('id')->on('partners')->restrictOnDelete();
            $t->foreign('payment_confirmation_id')->references('id')->on('payment_confirmations')->restrictOnDelete();
            $t->index(['customer_partner_id', 'status']);
        });
        Ddl::enumCheck('payments', 'status', PaymentStatus::class);
        Ddl::enumCheck('payments', 'method', PaymentMethod::class);
        Ddl::check('payments', 'chk_payments_amount', 'amount > 0');
        // The same bank/mobile transaction can be recorded as a payment only once.
        Ddl::uniqueExpression('payments', 'payments_reference_posted_uq', "CASE WHEN bank_reference IS NOT NULL AND status = 'posted' THEN lower(bank_reference) END");
        Schema::table('payment_confirmations', function (Blueprint $t) {
            $t->foreign('payment_id')->references('id')->on('payments')->restrictOnDelete();
        });
        Ddl::appendOnly('payments');

        Schema::create('payment_allocations', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('payment_id');
            $t->uuid('invoice_id');
            $t->decimal('amount', 20, 4);
            $t->uuid('allocated_by')->nullable();
            $t->dateTime('created_at', 6);
            $t->uuid('reversed_by')->nullable();
            $t->dateTime('reversed_at', 6)->nullable();
            $t->text('reversal_reason')->nullable();
            $t->foreign('payment_id')->references('id')->on('payments')->restrictOnDelete();
            $t->foreign('invoice_id')->references('id')->on('invoices')->restrictOnDelete();
            $t->index('payment_id');
            $t->index('invoice_id');
        });
        Ddl::check('payment_allocations', 'chk_payment_allocations_amount', 'amount > 0');
        Ddl::appendOnly('payment_allocations');

        // ---- Delivery acknowledgements --------------------------------------------------------------
        Schema::create('delivery_acknowledgements', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('customer_partner_id');
            $t->uuid('sales_order_id');
            $t->uuid('shipment_id')->nullable();
            $t->uuid('account_id');
            Ddl::enumColumn($t, 'outcome');
            Ddl::enumColumn($t, 'status', default: AcknowledgementStatus::RECORDED->value);
            $t->text('note')->nullable();
            $t->text('resolution_note')->nullable();
            $t->uuid('resolved_by')->nullable();
            $t->dateTime('resolved_at', 6)->nullable();
            $t->integer('version')->default(1);
            Ddl::timestamps($t);
            $t->foreign('customer_partner_id')->references('id')->on('partners')->restrictOnDelete();
            $t->foreign('sales_order_id')->references('id')->on('sales_orders')->restrictOnDelete();
            $t->foreign('shipment_id')->references('id')->on('shipments')->restrictOnDelete();
            $t->foreign('account_id')->references('id')->on('user_accounts')->restrictOnDelete();
            $t->index(['customer_partner_id', 'status']);
        });
        Ddl::enumCheck('delivery_acknowledgements', 'outcome', DeliveryOutcome::class);
        Ddl::enumCheck('delivery_acknowledgements', 'status', AcknowledgementStatus::class);
        // One acknowledgement per shipment, or per order delivered without a shipment.
        // Unique indexes ignore NULLs, so the first needs no predicate.
        DB::statement('CREATE UNIQUE INDEX delivery_ack_shipment_uq ON delivery_acknowledgements (shipment_id)');
        Ddl::uniqueExpression('delivery_acknowledgements', 'delivery_ack_order_uq', 'CASE WHEN shipment_id IS NULL THEN sales_order_id END');

        Schema::create('delivery_acknowledgement_lines', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('acknowledgement_id');
            $t->uuid('sales_order_line_id');
            $t->uuid('item_id');
            Ddl::enumColumn($t, 'issue');
            $t->decimal('quantity', 20, 6)->nullable();
            $t->text('note')->nullable();
            $t->dateTime('created_at', 6)->nullable();
            $t->foreign('acknowledgement_id')->references('id')->on('delivery_acknowledgements')->restrictOnDelete();
            $t->foreign('sales_order_line_id')->references('id')->on('sales_order_lines')->restrictOnDelete();
        });
        Ddl::enumCheck('delivery_acknowledgement_lines', 'issue', DeliveryIssueType::class);
        Ddl::check('delivery_acknowledgement_lines', 'chk_dal_quantity', 'quantity IS NULL OR quantity > 0');
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
        Ddl::dropCheckIfExists('user_accounts', 'chk_user_accounts_customer_partner');
        Schema::table('user_accounts', function (Blueprint $t) {
            $t->dropForeign(['customer_partner_id']);
            $t->dropColumn('customer_partner_id');
        });
    }
};
