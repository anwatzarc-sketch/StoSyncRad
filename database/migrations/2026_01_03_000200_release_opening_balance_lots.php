<?php

use App\Domains\Audit\Services\AuditRecorder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Opening balances used to register lots as QC-pending when the item's category inspects on receipt,
 * although the stock itself was posted as available — so no transfer, pick or sale could use it.
 * Stock declared available at cut-off was accepted before go-live: release those lots (the person who
 * posted the opening balance is recorded as releasing them). Lots entered in another condition
 * (quarantine, damaged, expired…) keep their QC status.
 */
return new class extends Migration
{
    public function up(): void
    {
        $lots = DB::table('inventory_lots as lot')
            ->join('opening_balance_documents as d', 'd.id', '=', 'lot.origin_document_id')
            ->where('lot.origin_document_type', 'opening_balance')
            ->where('lot.qc_status', 'pending')
            ->where('d.status', 'posted')
            ->whereExists(fn ($q) => $q->from('opening_balance_lines as l')->whereColumn('l.document_id', 'd.id')->whereColumn('l.item_id', 'lot.item_id')
                ->whereColumn('l.lot_number', 'lot.lot_number')->where('l.stock_condition', 'available'))
            ->get(['lot.id', 'lot.lot_number', 'd.document_number', 'd.posted_by']);

        foreach ($lots as $lot) {
            DB::table('inventory_lots')->where('id', $lot->id)->update(['qc_status' => 'released', 'qc_released_by' => $lot->posted_by,
                'qc_released_at' => now(), 'updated_at' => now()]);
            app(AuditRecorder::class)->record(null, 'inventory.lot.qc_released', 'inventory_lots', $lot->id, ['qc_status' => 'pending'],
                ['qc_status' => 'released'], "Opening stock posted as available on {$lot->document_number}");
        }
    }

    public function down(): void
    {
        // Releasing is a business decision recorded in the audit log; it is not undone automatically.
    }
};
