<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer Portal round 2: customer administrators (invitations by customers), tax rates per item and
 * per invoice line, partial invoicing (several invoices per order, one per shipment), and customer
 * credit limits with recorded overrides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_invitations', function (Blueprint $t) {
            $t->boolean('as_admin')->default(false);
        });

        Schema::create('tax_rates', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->string('code', 40)->unique();
            $t->string('name', 120);
            $t->decimal('rate', 8, 4);
            $t->boolean('is_default')->default(false);
            $t->boolean('is_active')->default(true);
            $t->text('description')->nullable();
            Pg::actorColumns($t);
            Pg::timestamps($t);
        });
        Pg::check('tax_rates', 'chk_tax_rates_rate', 'rate >= 0 AND rate <= 100');
        DB::statement('CREATE UNIQUE INDEX tax_rates_single_default_uq ON tax_rates (is_default) WHERE is_default');

        Schema::table('items', function (Blueprint $t) {
            $t->uuid('tax_rate_id')->nullable();
            $t->foreign('tax_rate_id')->references('id')->on('tax_rates')->restrictOnDelete();
        });

        Schema::table('invoice_lines', function (Blueprint $t) {
            $t->decimal('tax_rate', 8, 4)->default(0);
            $t->decimal('tax_amount', 20, 4)->default(0);
        });

        // Several invoices per order (partial / per-shipment invoicing); quantities are checked by the service.
        DB::statement('DROP INDEX IF EXISTS invoices_sales_order_live_uq');
        Schema::table('invoices', function (Blueprint $t) {
            $t->uuid('shipment_id')->nullable();
            $t->foreign('shipment_id')->references('id')->on('shipments')->restrictOnDelete();
            $t->index('sales_order_id');
        });
        DB::statement("CREATE UNIQUE INDEX invoices_shipment_live_uq ON invoices (shipment_id) WHERE shipment_id IS NOT NULL AND status <> 'void'");

        Schema::table('partners', function (Blueprint $t) {
            $t->decimal('credit_limit', 20, 4)->nullable();
        });
        Pg::check('partners', 'chk_partners_credit_limit', 'credit_limit IS NULL OR credit_limit >= 0');

        Schema::table('sales_orders', function (Blueprint $t) {
            $t->uuid('credit_override_by')->nullable();
            $t->text('credit_override_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', fn (Blueprint $t) => $t->dropColumn(['credit_override_by', 'credit_override_reason']));
        DB::statement('ALTER TABLE partners DROP CONSTRAINT IF EXISTS chk_partners_credit_limit');
        Schema::table('partners', fn (Blueprint $t) => $t->dropColumn('credit_limit'));
        DB::statement('DROP INDEX IF EXISTS invoices_shipment_live_uq');
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropForeign(['shipment_id']);
            $t->dropIndex(['sales_order_id']);
            $t->dropColumn('shipment_id');
        });
        Schema::table('invoice_lines', fn (Blueprint $t) => $t->dropColumn(['tax_rate', 'tax_amount']));
        Schema::table('items', function (Blueprint $t) {
            $t->dropForeign(['tax_rate_id']);
            $t->dropColumn('tax_rate_id');
        });
        Schema::dropIfExists('tax_rates');
        Schema::table('customer_invitations', fn (Blueprint $t) => $t->dropColumn('as_admin'));
    }
};
