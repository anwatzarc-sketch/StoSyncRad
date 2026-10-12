<?php

declare(strict_types=1);

namespace App\Domains\Platform\Seeds;

use App\Domains\Platform\Enums\TemplateEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * System default templates (Chapter 03 §3.6), read from resources/templates (sandboxed SafeTemplateRenderer syntax, never PHP). Defaults are inserted
 * once; client edits are separate rows/versions and are never overwritten.
 */
final class TemplatesSeed extends Seed
{
    /** code → [name, engine, file] */
    public const TEMPLATES = [
        'document.purchase_order.print' => ['Purchase order', TemplateEngine::SAFE_HTML, 'documents/purchase_order.html'],
        'document.goods_receipt.print' => ['Goods receipt note', TemplateEngine::SAFE_HTML, 'documents/goods_receipt.html'],
        'document.transfer.dispatch_note' => ['Transfer dispatch note', TemplateEngine::SAFE_HTML, 'documents/transfer_dispatch.html'],
        'document.transfer.receipt_note' => ['Transfer receipt note', TemplateEngine::SAFE_HTML, 'documents/transfer_receipt.html'],
        'document.stock_issue.print' => ['Stock issue note', TemplateEngine::SAFE_HTML, 'documents/stock_issue.html'],
        'document.pick_list.print' => ['Pick list', TemplateEngine::SAFE_HTML, 'documents/pick_list.html'],
        'document.delivery_note.print' => ['Delivery note', TemplateEngine::SAFE_HTML, 'documents/delivery_note.html'],
        'document.stocktake.count_sheet' => ['Stocktake count sheet', TemplateEngine::SAFE_HTML, 'documents/stocktake_sheet.html'],
        'document.stock_adjustment.print' => ['Stock adjustment', TemplateEngine::SAFE_HTML, 'documents/stock_adjustment.html'],
        'label.item.barcode' => ['Item barcode label', TemplateEngine::LABEL_HTML, 'labels/item_barcode.html'],
        'report.pdf.layout.default' => ['Default report PDF layout', TemplateEngine::SAFE_HTML, 'reports/default_layout.html'],
        'email.layout.default' => ['Default email layout', TemplateEngine::SAFE_HTML, 'emails/layout.html'],
        'email.notification.generic' => ['Notification email', TemplateEngine::SAFE_HTML, 'emails/notification.html'],
        'email.auth.password_reset' => ['Password reset email', TemplateEngine::SAFE_HTML, 'emails/password_reset.html'],
        'email.auth.welcome' => ['Welcome email', TemplateEngine::SAFE_HTML, 'emails/welcome.html'],
    ];

    public function name(): string
    {
        return 'templates';
    }

    public function version(): string
    {
        return '2.0.0';
    }

    public function run(array $options = []): array
    {
        $inserted = 0;
        foreach (self::TEMPLATES as $code => [$name, $engine, $file]) {
            $path = resource_path('templates/'.$file);
            if (! is_file($path) || DB::table('templates')->where('code', $code)->exists()) {
                continue;
            }
            DB::table('templates')->insert([
                'id' => (string) Str::uuid7(), 'code' => $code, 'name' => $name, 'engine' => $engine->value,
                'body' => (string) file_get_contents($path), 'variables_schema' => null, 'version' => 1,
                'is_system_default' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $inserted++;
        }

        return ['inserted' => $inserted];
    }
}
