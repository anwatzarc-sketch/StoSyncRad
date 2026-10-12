<?php

declare(strict_types=1);

namespace App\Domains\Platform\Templates;

use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Services\Access;
use App\Domains\Platform\Config\PlatformConfig;
use App\Domains\Platform\Services\BrandResolver;
use App\Support\Enums\EnumLabelResolver;
use App\Support\Enums\EnumRegistry;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;

/**
 * Printable documents and labels (Chapter 03 §3.6): gathers plain data for a document, renders the
 * client's active template with the sandboxed renderer inside a code-owned branded shell, and
 * returns HTML or PDF. Authorization follows the document's own read permission and warehouse.
 */
final class DocumentPrintService
{
    /** print type => [template code, permission, warehouse column(s) source] */
    public const TYPES = [
        'purchase_order' => ['document.purchase_order.print', 'procurement.purchase_order.read'],
        'goods_receipt' => ['document.goods_receipt.print', 'receiving.goods_receipt.read'],
        'transfer_dispatch' => ['document.transfer.dispatch_note', 'transfers.transfer.read'],
        'transfer_receipt' => ['document.transfer.receipt_note', 'transfers.transfer.read'],
        'stock_issue' => ['document.stock_issue.print', 'fulfillment.stock_issue.read'],
        'pick_list' => ['document.pick_list.print', 'fulfillment.pick_list.read'],
        'delivery_note' => ['document.delivery_note.print', 'fulfillment.shipment.read'],
        'stocktake' => ['document.stocktake.count_sheet', 'stocktaking.stocktake.read'],
        'stock_adjustment' => ['document.stock_adjustment.print', 'stocktaking.adjustment.read'],
        'item_label' => ['label.item.barcode', 'catalog.label.print'],
    ];

    public function __construct(
        private readonly Access $access,
        private readonly PlatformConfig $config,
        private readonly BrandResolver $brand,
        private readonly EnumLabelResolver $labels,
        private readonly EnumRegistry $enums,
    ) {}

    /** @return array{content: string, mime: string, filename: string} */
    public function print(string $type, string $id, UserAccount $actor, string $format = 'pdf', array $options = []): array
    {
        [$code, $permission] = self::TYPES[$type] ?? throw DomainException::of(ErrorCode::NOT_FOUND, "Unknown printable document `$type`.");
        [$data, $warehouses] = $this->data($type, $id, $actor, $options);
        $allowed = array_filter($warehouses) === [] ? $this->access->hasPermissionAnywhere($actor, $permission)
            : collect($warehouses)->filter()->contains(fn ($w) => $this->access->can($actor, $permission, $w));
        if (! $allowed) {
            throw DomainException::permissionDenied($permission);
        }
        $template = DB::table('templates')->where('code', $code)->where('is_active', true)->orderByDesc('is_system_default')->orderByDesc('updated_at')
            ->value('body') ?? throw DomainException::of(ErrorCode::NOT_FOUND, "No active template `$code`. Re-run the template seed or create one.");
        $html = $this->shell($this->renderer()->render((string) $template, $data + $this->common($actor)), $data['title'] ?? $type, $type === 'item_label');
        $filename = ($data['document']['document_number'] ?? $type).'.'.($format === 'html' ? 'html' : 'pdf');
        if ($format === 'html') {
            return ['content' => $html, 'mime' => 'text/html; charset=utf-8', 'filename' => $filename];
        }
        $pdf = Pdf::loadHTML($html)->setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $type === 'item_label' ? $pdf->setPaper([0, 0, 170.08, 113.39]) : $pdf->setPaper('a4', 'portrait');   // 60×40 mm labels

        return ['content' => $pdf->output(), 'mime' => 'application/pdf', 'filename' => $filename];
    }

    /** Render a template body against sample data (template editor preview). */
    public function preview(string $body, array $sample): string
    {
        return $this->shell($this->renderer()->render($body, $sample + ['brand' => $this->brand->publicProfile()]), 'Preview', false);
    }

    public function renderer(): SafeTemplateRenderer
    {
        return new SafeTemplateRenderer((string) $this->config->get('locale.date_format') ?: 'Y-m-d', (string) $this->config->get('locale.timezone') ?: 'UTC');
    }

    /** @return array{0: array<string, mixed>, 1: array<int, ?string>} [data, warehouse ids for authorization] */
    private function data(string $type, string $id, UserAccount $actor, array $options): array
    {
        $one = fn (string $sql, array $b) => (array) (DB::selectOne($sql, $b) ?? throw DomainException::notFound(str_replace('_', ' ', $type)));
        $many = fn (string $sql, array $b) => array_map(fn ($r) => (array) $r, DB::select($sql, $b));

        switch ($type) {
            case 'purchase_order':
                $doc = $one('SELECT o.*, s.name AS supplier_name, s.code AS supplier_code, s.tax_id AS supplier_tax_id, s.addresses AS supplier_addresses,
                    s.email AS supplier_email, s.phone AS supplier_phone, w.code AS warehouse_code, w.name AS warehouse_name, w.address AS warehouse_address
                    FROM purchase_orders o JOIN partners s ON s.id = o.supplier_id JOIN warehouses w ON w.id = o.warehouse_id WHERE o.id = ?', [$id]);
                $lines = $many('SELECT l.line_no, i.sku, i.name, l.quantity, u.symbol AS uom, l.unit_price, l.discount_percent, l.tax_percent, l.line_total,
                    l.expected_date FROM purchase_order_lines l JOIN items i ON i.id = l.item_id JOIN units_of_measure u ON u.id = l.uom_id
                    WHERE l.purchase_order_id = ? ORDER BY l.line_no', [$id]);

                return [['title' => 'Purchase order', 'document' => $this->decode($doc), 'lines' => $lines], [$doc['warehouse_id']]];
            case 'goods_receipt':
                $doc = $one('SELECT g.*, s.name AS supplier_name, o.document_number AS purchase_order_number, w.code AS warehouse_code, w.name AS warehouse_name
                    FROM goods_receipts g JOIN warehouses w ON w.id = g.warehouse_id LEFT JOIN partners s ON s.id = g.supplier_id
                    LEFT JOIN purchase_orders o ON o.id = g.purchase_order_id WHERE g.id = ?', [$id]);
                $lines = $many('SELECT l.line_no, i.sku, i.name, l.expected_quantity, l.received_quantity, l.accepted_quantity, l.rejected_quantity,
                    u.symbol AS uom, COALESCE(lt.lot_number, l.lot_number) AS lot_number, l.expiry_date, l.resulting_condition
                    FROM goods_receipt_lines l JOIN items i ON i.id = l.item_id JOIN units_of_measure u ON u.id = l.uom_id
                    LEFT JOIN inventory_lots lt ON lt.id = l.inventory_lot_id WHERE l.goods_receipt_id = ? ORDER BY l.line_no', [$id]);

                return [['title' => 'Goods receipt note', 'document' => $this->decode($doc), 'lines' => $this->label($lines, 'resulting_condition', 'stock_condition')], [$doc['warehouse_id']]];
            case 'transfer_dispatch':
                $doc = $one('SELECT d.*, t.document_number AS transfer_number, t.source_warehouse_id, t.destination_warehouse_id, sw.name AS source_name,
                    sw.code AS source_code, dw.name AS destination_name, dw.code AS destination_code, c.name AS carrier_name
                    FROM transfer_dispatches d JOIN transfer_orders t ON t.id = d.transfer_order_id JOIN warehouses sw ON sw.id = t.source_warehouse_id
                    JOIN warehouses dw ON dw.id = t.destination_warehouse_id LEFT JOIN partners c ON c.id = d.carrier_partner_id WHERE d.id = ?', [$id]);
                $lines = $many('SELECT i.sku, i.name, lt.lot_number, lt.expiry_date, s.serial_number, l.base_quantity AS quantity, u.symbol AS uom, sl.full_path AS from_location
                    FROM transfer_dispatch_lines l JOIN items i ON i.id = l.item_id JOIN units_of_measure u ON u.id = i.base_uom_id
                    LEFT JOIN inventory_lots lt ON lt.id = l.inventory_lot_id LEFT JOIN item_serials s ON s.id = l.item_serial_id
                    LEFT JOIN storage_locations sl ON sl.id = l.source_storage_location_id WHERE l.transfer_dispatch_id = ? ORDER BY i.sku, lt.lot_number', [$id]);

                return [['title' => 'Transfer dispatch note', 'document' => $doc, 'lines' => $lines], [$doc['source_warehouse_id'], $doc['destination_warehouse_id']]];
            case 'transfer_receipt':
                $doc = $one('SELECT r.*, t.document_number AS transfer_number, t.source_warehouse_id, t.destination_warehouse_id, sw.name AS source_name, dw.name AS destination_name
                    FROM transfer_receipts r JOIN transfer_orders t ON t.id = r.transfer_order_id JOIN warehouses sw ON sw.id = t.source_warehouse_id
                    JOIN warehouses dw ON dw.id = t.destination_warehouse_id WHERE r.id = ?', [$id]);
                $lines = $many('SELECT i.sku, i.name, lt.lot_number, dl.base_quantity AS dispatched_quantity, l.accepted_base_quantity AS accepted_quantity,
                    l.damaged_base_quantity AS damaged_quantity, l.shortage_base_quantity AS shortage_quantity, l.excess_base_quantity AS excess_quantity, l.notes
                    FROM transfer_receipt_lines l JOIN transfer_dispatch_lines dl ON dl.id = l.transfer_dispatch_line_id JOIN items i ON i.id = dl.item_id
                    LEFT JOIN inventory_lots lt ON lt.id = dl.inventory_lot_id WHERE l.transfer_receipt_id = ? ORDER BY i.sku', [$id]);

                return [['title' => 'Transfer receipt note', 'document' => $doc, 'lines' => $lines], [$doc['source_warehouse_id'], $doc['destination_warehouse_id']]];
            case 'stock_issue':
                $doc = $one('SELECT s.*, r.document_number AS request_number, r.purpose, p.name AS recipient_partner_name, w.code AS warehouse_code, w.name AS warehouse_name
                    FROM stock_issues s JOIN warehouses w ON w.id = s.warehouse_id LEFT JOIN stock_requests r ON r.id = s.stock_request_id
                    LEFT JOIN partners p ON p.id = s.recipient_partner_id WHERE s.id = ?', [$id]);
                $lines = $many('SELECT l.line_no, i.sku, i.name, l.quantity, u.symbol AS uom, lt.lot_number, sr.serial_number, sl.full_path AS location
                    FROM stock_issue_lines l JOIN items i ON i.id = l.item_id JOIN units_of_measure u ON u.id = l.uom_id JOIN storage_locations sl ON sl.id = l.storage_location_id
                    LEFT JOIN inventory_lots lt ON lt.id = l.inventory_lot_id LEFT JOIN item_serials sr ON sr.id = l.item_serial_id
                    WHERE l.stock_issue_id = ? ORDER BY l.line_no', [$id]);

                return [['title' => 'Stock issue note', 'document' => $doc, 'lines' => $lines], [$doc['warehouse_id']]];
            case 'pick_list':
                $doc = $one('SELECT p.*, w.code AS warehouse_code, w.name AS warehouse_name FROM pick_lists p JOIN warehouses w ON w.id = p.warehouse_id WHERE p.id = ?', [$id]);
                $lines = $many('SELECT l.line_no, sl.full_path AS location, i.sku, i.name, lt.lot_number, lt.expiry_date, s.serial_number,
                    l.suggested_base_quantity AS quantity, u.symbol AS uom FROM pick_list_lines l JOIN items i ON i.id = l.item_id
                    JOIN units_of_measure u ON u.id = i.base_uom_id JOIN storage_locations sl ON sl.id = l.storage_location_id
                    LEFT JOIN inventory_lots lt ON lt.id = l.inventory_lot_id LEFT JOIN item_serials s ON s.id = l.item_serial_id
                    WHERE l.pick_list_id = ? ORDER BY l.sort_order, l.line_no', [$id]);

                return [['title' => 'Pick list', 'document' => $doc, 'lines' => $lines], [$doc['warehouse_id']]];
            case 'delivery_note':
                $doc = $one('SELECT s.*, o.document_number AS order_number, o.external_order_id, o.shipping_address, c.name AS customer_name,
                    cr.name AS carrier_name, w.name AS warehouse_name FROM shipments s JOIN sales_orders o ON o.id = s.sales_order_id
                    JOIN partners c ON c.id = o.customer_partner_id LEFT JOIN partners cr ON cr.id = s.carrier_partner_id JOIN warehouses w ON w.id = s.warehouse_id
                    WHERE s.id = ?', [$id]);
                $lines = $many('SELECT p.package_no, i.sku, i.name, lt.lot_number, sr.serial_number, l.base_quantity AS quantity, u.symbol AS uom
                    FROM shipment_package_lines l JOIN shipment_packages p ON p.id = l.shipment_package_id JOIN items i ON i.id = l.item_id
                    JOIN units_of_measure u ON u.id = i.base_uom_id LEFT JOIN inventory_lots lt ON lt.id = l.inventory_lot_id
                    LEFT JOIN item_serials sr ON sr.id = l.item_serial_id WHERE p.shipment_id = ? ORDER BY p.package_no, i.sku', [$id]);

                return [['title' => 'Delivery note', 'document' => $this->decode($doc), 'lines' => $lines], [$doc['warehouse_id']]];
            case 'stocktake':
                $doc = $one('SELECT s.*, w.code AS warehouse_code, w.name AS warehouse_name FROM stocktakes s JOIN warehouses w ON w.id = s.warehouse_id WHERE s.id = ?', [$id]);
                $showBook = ! $doc['is_blind'] || $this->access->can($actor, 'stocktaking.stocktake.view_book_qty', $doc['warehouse_id']);
                $lines = $many('SELECT l.line_no, sl.full_path AS location, i.sku, i.name, lt.lot_number, l.stock_condition,
                    CASE WHEN ?::boolean THEN l.snapshot_quantity END AS book_quantity, l.counted_quantity
                    FROM stocktake_lines l JOIN items i ON i.id = l.item_id JOIN storage_locations sl ON sl.id = l.storage_location_id
                    LEFT JOIN inventory_lots lt ON lt.id = l.inventory_lot_id WHERE l.stocktake_id = ? ORDER BY sl.sort_order, sl.full_path, i.sku', [$showBook, $id]);

                return [['title' => 'Stocktake count sheet', 'document' => $doc + ['show_book_quantity' => $showBook], 'lines' => $lines], [$doc['warehouse_id']]];
            case 'stock_adjustment':
                $doc = $one('SELECT a.*, w.code AS warehouse_code, w.name AS warehouse_name FROM stock_adjustments a JOIN warehouses w ON w.id = a.warehouse_id WHERE a.id = ?', [$id]);
                $lines = $many('SELECT l.line_no, sl.full_path AS location, i.sku, i.name, COALESCE(lt.lot_number, l.lot_number) AS lot_number, l.stock_condition,
                    l.quantity, l.value, l.reason FROM stock_adjustment_lines l JOIN items i ON i.id = l.item_id JOIN storage_locations sl ON sl.id = l.storage_location_id
                    LEFT JOIN inventory_lots lt ON lt.id = l.inventory_lot_id WHERE l.stock_adjustment_id = ? ORDER BY l.line_no', [$id]);
                $doc['adjustment_type'] = $this->enumLabel('adjustment_type', (string) $doc['adjustment_type']);

                return [['title' => 'Stock adjustment', 'document' => $doc, 'lines' => $lines], [$doc['warehouse_id']]];
            case 'item_label':
                $item = $one("SELECT i.id, i.sku, i.name, i.list_price, i.list_price_currency, u.symbol AS uom,
                    (SELECT b.barcode FROM item_barcodes b WHERE b.item_id = i.id ORDER BY b.is_primary DESC, b.created_at LIMIT 1) AS barcode
                    FROM items i JOIN units_of_measure u ON u.id = i.base_uom_id WHERE i.id = ?", [$id]);
                $copies = max(1, min(500, (int) ($options['copies'] ?? 1)));

                return [['title' => 'Labels', 'labels' => array_fill(0, $copies, $item + ['barcode' => $item['barcode'] ?: $item['sku']])], []];
        }
        throw DomainException::of(ErrorCode::NOT_FOUND, "Unknown printable document `$type`.");
    }

    /** @return array<string, mixed> */
    private function common(UserAccount $actor): array
    {
        $company = (array) (DB::table('company_profile')->first() ?? []);
        foreach (['registered_address', 'operational_address'] as $k) {
            $company[$k] = isset($company[$k]) ? json_decode((string) $company[$k], true) : null;
        }

        return ['brand' => $this->brand->publicProfile(), 'company' => $company, 'printed_at' => now()->toIso8601String(), 'printed_by' => $actor->displayName()];
    }

    private function shell(string $body, string $title, bool $label): string
    {
        $brand = $this->brand->publicProfile();
        $primary = e((string) data_get($brand, 'theme.colors.primary', '#1f4b99'));
        $page = $label ? '@page { margin: 4mm; }' : '@page { margin: 18mm 14mm 16mm 14mm; }';

        return '<!doctype html><html><head><meta charset="utf-8"><title>'.e($title).'</title><style>'.$page.'
            body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; color: #1d2433; }
            h1 { font-size: 16px; margin: 0 0 6px; color: '.$primary.'; } h2 { font-size: 12px; margin: 10px 0 4px; }
            table { width: 100%; border-collapse: collapse; } th { text-align: left; background: #eef1f7; border-bottom: 1px solid #c5ccd8; padding: 4px; }
            td { padding: 3px 4px; border-bottom: 1px solid #e6e9ef; vertical-align: top; } .n { text-align: right; } .muted { color: #5b6475; }
            .grid { width: 100%; } .grid td { border: none; padding: 2px 0; } .sign { margin-top: 28px; } .sign td { border: none; padding-top: 26px; border-top: 1px solid #999; width: 33%; }
            .brand { border-bottom: 2px solid '.$primary.'; padding-bottom: 6px; margin-bottom: 10px; } .brand .name { font-weight: bold; font-size: 14px; color: '.$primary.'; }
            .label { page-break-after: always; text-align: center; } .label:last-child { page-break-after: auto; }
            </style></head><body>'.$body.'</body></html>';
    }

    /** JSON columns arrive as strings from raw queries. */
    private function decode(array $row): array
    {
        foreach ($row as $k => $v) {
            if (is_string($v) && in_array($k, ['shipping_address', 'supplier_addresses', 'warehouse_address', 'address'], true)) {
                $row[$k] = json_decode($v, true);
            }
        }

        return $row;
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    private function label(array $rows, string $column, string $enumCode): array
    {
        return array_map(fn ($r) => [$column => $this->enumLabel($enumCode, (string) ($r[$column] ?? ''))] + $r, $rows);
    }

    private function enumLabel(string $enumCode, string $value): string
    {
        $class = $this->enums->byCode($enumCode);
        $case = $class !== null ? $class::tryFrom($value) : null;

        return $case !== null ? $this->labels->label($case) : $value;
    }
}
