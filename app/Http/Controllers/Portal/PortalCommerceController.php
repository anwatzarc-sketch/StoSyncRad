<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\PaymentConfirmation;
use App\Domains\Billing\Services\EvidenceStore;
use App\Domains\Billing\Services\PaymentConfirmationWorkflow;
use App\Domains\CustomerPortal\Services\CustomerContext;
use App\Domains\CustomerPortal\Services\DeliveryAcknowledgementService;
use App\Domains\CustomerPortal\Services\PortalOrders;
use App\Domains\Fulfillment\Models\SalesOrder;
use App\Domains\Fulfillment\Services\SalesOrderWorkflow;
use App\Domains\Platform\Enums\AttachableType;
use App\Domains\Platform\Models\DocumentAttachment;
use App\Domains\Platform\Templates\DocumentPrintService;
use App\Domains\Sales\Models\Quotation;
use App\Domains\Sales\Services\QuotationWorkflow;
use App\Http\Controllers\Api\ApiController;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The customer's quotations, orders, deliveries, invoices and payment confirmations (CP-1.1 section 5).
 * Records are always looked up within the signed-in customer; responses are shaped explicitly so no
 * internal field (costs, locations, staff ids, other customers) leaves the server.
 */
final class PortalCommerceController extends ApiController
{
    public function __construct(private readonly CustomerContext $context) {}

    // ---- Quotations ---------------------------------------------------------------------------------

    public function quotations(Request $request): JsonResponse
    {
        $customer = $this->context->partnerFor($this->actor($request));

        return response()->json(['data' => Quotation::query()->where('customer_partner_id', $customer->id)->orderByDesc('created_at')->limit(200)->get()
            ->map(fn (Quotation $q) => $this->quotationSummary($q))->all()]);
    }

    public function quotation(Request $request, string $id): JsonResponse
    {
        $quotation = $this->context->own(Quotation::class, $id, $this->context->partnerFor($this->actor($request)));
        $priced = ! in_array($quotation->status->value, ['requested', 'revision_requested'], true) || $quotation->revision > 0;
        $lines = DB::table('quotation_lines as l')->join('items as i', 'i.id', '=', 'l.item_id')->join('units_of_measure as u', 'u.id', '=', 'l.uom_id')
            ->where('l.quotation_id', $quotation->id)->orderBy('l.line_no')
            ->get(['l.id', 'l.line_no', 'i.sku', 'i.name', 'u.symbol as unit', 'l.quantity', 'l.unit_price', 'l.line_total', 'l.notes'])
            ->map(fn ($l) => ['id' => $l->id, 'line_no' => $l->line_no, 'sku' => $l->sku, 'name' => $l->name, 'unit' => $l->unit, 'quantity' => (string) $l->quantity,
                'unit_price' => $priced && $l->unit_price !== null ? (string) $l->unit_price : null, 'line_total' => $priced ? (string) $l->line_total : null, 'notes' => $l->notes])->all();

        return response()->json(['data' => $this->quotationSummary($quotation) + [
            'lines' => $lines, 'customer_note' => $quotation->customer_note, 'staff_note' => $quotation->staff_note, 'revision_note' => $quotation->revision_note,
            'can_respond' => $quotation->status->value === 'sent' && ($quotation->valid_until === null || ! $quotation->valid_until->lt(now()->startOfDay())),
            'order' => $quotation->sales_order_id === null ? null : ['id' => $quotation->sales_order_id,
                'document_number' => SalesOrder::query()->whereKey($quotation->sales_order_id)->value('document_number')],
        ]]);
    }

    public function requestQuotation(Request $request, QuotationWorkflow $quotations): JsonResponse
    {
        $account = $this->actor($request);
        $quotation = $quotations->request($account, $this->context->partnerFor($account, forAction: true), $this->payload($request), $this->idempotencyKey($request));

        return response()->json(['data' => $this->quotationSummary($quotation)], 201);
    }

    public function acceptQuotation(Request $request, string $id, QuotationWorkflow $quotations): JsonResponse
    {
        $account = $this->actor($request);
        $order = $quotations->accept($id, $account, $this->context->partnerFor($account, forAction: true));

        return response()->json(['data' => ['order' => ['id' => $order->id, 'document_number' => $order->document_number]]]);
    }

    public function declineQuotation(Request $request, string $id, QuotationWorkflow $quotations): JsonResponse
    {
        $account = $this->actor($request);
        $quotation = $quotations->decline($id, $account, $this->context->partnerFor($account), $this->payload($request)['reason'] ?? null);

        return response()->json(['data' => $this->quotationSummary($quotation)]);
    }

    public function reviseQuotation(Request $request, string $id, QuotationWorkflow $quotations): JsonResponse
    {
        $account = $this->actor($request);
        $quotation = $quotations->requestRevision($id, $account, $this->context->partnerFor($account), $this->payload($request)['reason'] ?? null);

        return response()->json(['data' => $this->quotationSummary($quotation)]);
    }

    public function quotationDocument(Request $request, string $id, DocumentPrintService $print): Response
    {
        $account = $this->actor($request);
        $quotation = $this->context->own(Quotation::class, $id, $this->context->partnerFor($account));
        if (in_array($quotation->status->value, ['requested', 'revision_requested', 'cancelled'], true)) {
            throw DomainException::of(ErrorCode::INVALID_STATE_TRANSITION, 'The quotation document is available once it is priced.');
        }

        return $this->pdf($print->print('quotation', $quotation->id, $account, preAuthorized: true));
    }

    // ---- Orders -------------------------------------------------------------------------------------

    public function orders(Request $request, PortalOrders $orders): JsonResponse
    {
        return response()->json(['data' => $orders->list($this->context->partnerFor($this->actor($request)), $request->query('status'))]);
    }

    public function order(Request $request, string $id, PortalOrders $orders): JsonResponse
    {
        $order = $this->context->own(SalesOrder::class, $id, $this->context->partnerFor($this->actor($request)));

        return response()->json(['data' => $orders->show($order)]);
    }

    public function placeOrder(Request $request, PortalOrders $orders): JsonResponse
    {
        $account = $this->actor($request);
        $order = $orders->place($account, $this->context->partnerFor($account, forAction: true), $this->payload($request), $this->idempotencyKey($request));

        return response()->json(['data' => ['id' => $order->id, 'document_number' => $order->document_number, 'status' => $order->status->value,
            'total' => (string) $order->total, 'currency' => $order->currency]], 201);
    }

    public function cancelOrder(Request $request, string $id, SalesOrderWorkflow $workflow): JsonResponse
    {
        $account = $this->actor($request);
        $order = $workflow->cancelByCustomer($id, $this->context->partnerFor($account), $account, $this->payload($request)['reason'] ?? null);

        return response()->json(['data' => ['id' => $order->id, 'status' => $order->status->value]]);
    }

    public function acknowledgeDelivery(Request $request, string $id, DeliveryAcknowledgementService $acks, ?string $shipmentId = null): JsonResponse
    {
        $account = $this->actor($request);
        $ack = $acks->submit($account, $this->context->partnerFor($account), $id, $shipmentId, $this->payload($request));

        return response()->json(['data' => ['id' => $ack->id, 'outcome' => $ack->outcome->value, 'status' => $ack->status->value,
            'recorded_at' => $ack->created_at?->toIso8601String()]], 201);
    }

    // ---- Invoices -----------------------------------------------------------------------------------

    public function invoices(Request $request): JsonResponse
    {
        $customer = $this->context->partnerFor($this->actor($request));

        return response()->json(['data' => Invoice::query()->where('customer_partner_id', $customer->id)->where('status', '<>', 'draft')
            ->orderByDesc('issue_date')->orderByDesc('created_at')->limit(300)->get()->map(fn (Invoice $i) => $this->invoiceSummary($i))->all()]);
    }

    public function invoice(Request $request, string $id): JsonResponse
    {
        $invoice = $this->ownInvoice($request, $id);
        $lines = DB::table('invoice_lines')->where('invoice_id', $invoice->id)->orderBy('line_no')->get(['line_no', 'description', 'quantity', 'unit_price', 'line_total', 'tax_rate', 'tax_amount'])
            ->map(fn ($l) => (array) $l)->all();
        $payments = DB::table('payment_allocations as a')->join('payments as p', 'p.id', '=', 'a.payment_id')->where('a.invoice_id', $invoice->id)->whereNull('a.reversed_at')
            ->orderBy('a.created_at')->get(['p.document_number', 'p.received_on', 'p.method', 'a.amount'])
            ->map(fn ($p) => ['document_number' => $p->document_number, 'received_on' => $p->received_on, 'method' => $p->method, 'amount' => (string) $p->amount])->all();
        $confirmations = PaymentConfirmation::query()->where('invoice_id', $invoice->id)->orderByDesc('created_at')->get()
            ->map(fn (PaymentConfirmation $c) => $this->confirmationSummary($c))->all();

        return response()->json(['data' => $this->invoiceSummary($invoice) + [
            'lines' => $lines, 'subtotal' => (string) $invoice->subtotal, 'tax_rate' => (string) $invoice->tax_rate, 'tax_amount' => (string) $invoice->tax_amount,
            'payments' => $payments, 'confirmations' => $confirmations, 'notes' => $invoice->notes,
            'order' => $invoice->sales_order_id === null ? null : ['id' => $invoice->sales_order_id,
                'document_number' => SalesOrder::query()->whereKey($invoice->sales_order_id)->value('document_number')],
            'can_confirm_payment' => in_array($invoice->status->value, ['issued', 'partially_paid'], true),
        ]]);
    }

    public function invoiceDocument(Request $request, string $id, DocumentPrintService $print): Response
    {
        $invoice = $this->ownInvoice($request, $id);

        return $this->pdf($print->print('invoice', $invoice->id, $this->actor($request), preAuthorized: true));
    }

    // ---- Payment confirmations ------------------------------------------------------------------------

    public function payments(Request $request): JsonResponse
    {
        $customer = $this->context->partnerFor($this->actor($request));

        return response()->json(['data' => PaymentConfirmation::query()->where('customer_partner_id', $customer->id)->orderByDesc('created_at')->limit(300)->get()
            ->map(fn (PaymentConfirmation $c) => $this->confirmationSummary($c))->all()]);
    }

    public function payment(Request $request, string $id): JsonResponse
    {
        $confirmation = $this->context->own(PaymentConfirmation::class, $id, $this->context->partnerFor($this->actor($request)));

        return response()->json(['data' => $this->confirmationSummary($confirmation) + [
            'payer_name' => $confirmation->payer_name, 'bank_name' => $confirmation->bank_name, 'customer_note' => $confirmation->customer_note,
            'review_note' => in_array($confirmation->status->value, ['info_requested', 'rejected', 'verified'], true) ? $confirmation->review_note : null,
            'evidence' => $confirmation->evidence()->get()->map(fn (DocumentAttachment $a) => ['id' => $a->id, 'filename' => $a->original_filename,
                'size' => $a->file_size, 'type' => $a->mime_type, 'uploaded_at' => $a->created_at?->toIso8601String()])->all(),
            'payment_number' => $confirmation->payment_id === null ? null : DB::table('payments')->whereKey($confirmation->payment_id)->value('document_number'),
        ]]);
    }

    public function submitPayment(Request $request, PaymentConfirmationWorkflow $workflow): JsonResponse
    {
        $account = $this->actor($request);
        $confirmation = $workflow->submit($account, $this->context->partnerFor($account, forAction: true), $this->payload($request), $this->files($request),
            $this->idempotencyKey($request));

        return response()->json(['data' => $this->confirmationSummary($confirmation)], 201);
    }

    public function addPaymentInformation(Request $request, string $id, PaymentConfirmationWorkflow $workflow): JsonResponse
    {
        $account = $this->actor($request);
        $confirmation = $workflow->addInformation($id, $account, $this->context->partnerFor($account), $this->payload($request)['note'] ?? null, $this->files($request));

        return response()->json(['data' => $this->confirmationSummary($confirmation)]);
    }

    public function withdrawPayment(Request $request, string $id, PaymentConfirmationWorkflow $workflow): JsonResponse
    {
        $account = $this->actor($request);

        return response()->json(['data' => $this->confirmationSummary($workflow->withdraw($id, $account, $this->context->partnerFor($account)))]);
    }

    public function evidence(Request $request, string $id, string $attachmentId, EvidenceStore $store): Response
    {
        $confirmation = $this->context->own(PaymentConfirmation::class, $id, $this->context->partnerFor($this->actor($request)));
        $attachment = DocumentAttachment::query()->whereKey($attachmentId)->where('document_type', AttachableType::PAYMENT_CONFIRMATION->value)
            ->where('document_id', $confirmation->id)->first() ?? throw DomainException::notFound('file');

        return $store->download($attachment);
    }

    // ---- Shapes -------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function quotationSummary(Quotation $q): array
    {
        $priced = ! in_array($q->status->value, ['requested'], true) || $q->revision > 0;

        return ['id' => $q->id, 'document_number' => $q->document_number, 'status' => $q->status->value, 'status_label' => $q->status->label(),
            'revision' => $q->revision, 'currency' => $priced ? $q->currency : null, 'total' => $priced ? (string) $q->total : null,
            'valid_until' => $q->valid_until?->toDateString(), 'reference' => $q->reference, 'created_at' => $q->created_at?->toIso8601String(),
            'sent_at' => $q->sent_at?->toIso8601String()];
    }

    /** @return array<string, mixed> */
    private function invoiceSummary(Invoice $i): array
    {
        return ['id' => $i->id, 'document_number' => $i->document_number, 'status' => $i->status->value, 'status_label' => $i->status->label(),
            'issue_date' => $i->issue_date?->toDateString(), 'due_date' => $i->due_date?->toDateString(), 'currency' => $i->currency,
            'total' => (string) $i->total, 'amount_paid' => (string) $i->amount_paid, 'balance_due' => (string) $i->balance_due, 'reference' => $i->reference,
            'overdue' => in_array($i->status->value, ['issued', 'partially_paid'], true) && $i->due_date !== null && $i->due_date->lt(now()->startOfDay())];
    }

    /** @return array<string, mixed> */
    private function confirmationSummary(PaymentConfirmation $c): array
    {
        return ['id' => $c->id, 'document_number' => $c->document_number, 'status' => $c->status->value, 'status_label' => $c->status->label(),
            'amount' => (string) $c->amount, 'currency' => $c->currency, 'paid_on' => $c->paid_on?->toDateString(), 'method' => $c->method->value,
            'method_label' => $c->method->label(), 'transaction_reference' => $c->transaction_reference,
            'invoice' => $c->invoice_id === null ? null : ['id' => $c->invoice_id, 'document_number' => Invoice::query()->whereKey($c->invoice_id)->value('document_number')],
            'order' => $c->sales_order_id === null ? null : ['id' => $c->sales_order_id, 'document_number' => SalesOrder::query()->whereKey($c->sales_order_id)->value('document_number')],
            'submitted_at' => $c->created_at?->toIso8601String()];
    }

    private function ownInvoice(Request $request, string $id): Invoice
    {
        $invoice = $this->context->own(Invoice::class, $id, $this->context->partnerFor($this->actor($request)));
        if ($invoice->status->value === 'draft') {
            throw DomainException::notFound('invoice');   // drafts are not shown to customers
        }

        return $invoice;
    }

    /** @return array<int, UploadedFile> */
    private function files(Request $request): array
    {
        $files = $request->file('evidence', []);

        return array_values(array_filter(is_array($files) ? $files : [$files], fn ($f) => $f instanceof UploadedFile));
    }

    /** @param  array{content: string, mime: string, filename: string}  $file */
    private function pdf(array $file): Response
    {
        return response($file['content'], 200, ['Content-Type' => $file['mime'], 'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
