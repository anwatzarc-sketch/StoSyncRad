<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\DataExchange\Enums\ImportType;
use App\Domains\DataExchange\Models\ExportFile;
use App\Domains\DataExchange\Models\ImportBatch;
use App\Domains\DataExchange\Models\ImportRow;
use App\Domains\DataExchange\Services\ImportService;
use App\Domains\IdentityAccess\Services\Access;
use App\Domains\Messaging\Enums\NotificationStatus;
use App\Domains\Messaging\Services\NotificationInbox;
use App\Domains\Platform\Config\PlatformConfig;
use App\Domains\Platform\Templates\DocumentPrintService;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Imports (Chapter 11 §11.9), private file downloads, notifications and printing. */
final class ExchangeController extends ApiController
{
    public function __construct(private readonly ImportService $imports, private readonly Access $access, private readonly PlatformConfig $config) {}

    public function importTypes(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $handlers = app(\App\Domains\DataExchange\Services\ImportHandlers::class);

        return response()->json(['data' => array_values(array_map(fn (ImportType $t) => ['type' => $t->value, 'label' => $t->label()] + $handlers->spec($t)
            + ['allowed' => $this->access->can($actor, "exchange.import.{$t->value}")], ImportType::cases()))]);
    }

    public function upload(Request $request): JsonResponse
    {
        $type = ImportType::tryFrom((string) ($request->route()->getAction('variant') ?? $request->input('type'))) ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED,
            'Allowed import types: '.implode(', ', ImportType::values()).'.', ['field' => 'type']);
        $file = $request->file('file') ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Attach a CSV or XLSX file.', ['field' => 'file']);
        if ($file->getSize() > 50 * 1024 * 1024) {
            throw DomainException::of(ErrorCode::PAYLOAD_TOO_LARGE, 'Import files are limited to 50 MB.');
        }

        return $this->respond($this->imports->upload($type, $file->getRealPath(), $file->getClientOriginalName(), $this->actor($request)), 201);
    }

    public function batch(Request $request, string $id): JsonResponse
    {
        $batch = ImportBatch::query()->findOrFail($id);
        $this->assertImport($request, $batch);
        $rows = ImportRow::query()->where('import_batch_id', $batch->id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', (string) $request->query('status')))
            ->orderBy('row_number')->cursorPaginate(100);

        return response()->json(['data' => $this->presenter()->model($batch) + ['rows' => $this->presenter()->collection($rows->items())],
            'meta' => ['next_cursor' => $rows->nextCursor()?->encode()]]);
    }

    public function confirm(Request $request, string $id): JsonResponse
    {
        return $this->respond($this->imports->confirm($id, $this->actor($request)), 202);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        return $this->respond($this->imports->cancel($id, $this->actor($request)));
    }

    public function errors(Request $request, string $id): JsonResponse
    {
        $file = $this->imports->errorFile($id, $this->actor($request));

        return response()->json(['data' => ['file_id' => $file->id, 'filename' => $file->filename, 'download_url' => url("/api/v1/files/{$file->id}")]], 201);
    }

    public function template(Request $request, string $type): Response
    {
        $t = ImportType::tryFrom($type) ?? abort(404);

        return response($this->imports->template($t), 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => "attachment; filename=\"{$t->value}-template.csv\""]);
    }

    /** Owner-only private file download (exports, error files). */
    public function file(Request $request, string $id): StreamedResponse
    {
        $file = ExportFile::query()->findOrFail($id);
        if ($file->owner_account_id !== $this->actor($request)->id || $file->expires_at->isPast()) {
            throw DomainException::notFound('File');
        }
        $file->forceFill(['download_count' => $file->download_count + 1, 'last_downloaded_at' => now()])->save();

        return Storage::disk($file->disk)->download($file->path, $file->filename, ['Content-Type' => $file->mime_type, 'Cache-Control' => 'private, no-store']);
    }

    // ---- Notifications ---------------------------------------------------------------------

    public function notifications(Request $request, NotificationInbox $inbox): JsonResponse
    {
        $page = $inbox->list($this->actor($request), NotificationStatus::tryFrom((string) $request->query('status')), (int) $request->query('per_page', 30));

        return response()->json(['data' => $this->presenter()->collection($page->items()), 'meta' => ['next_cursor' => $page->nextCursor()?->encode(),
            'unread' => $inbox->unreadCount($this->actor($request))]]);
    }

    public function markNotifications(Request $request, NotificationInbox $inbox): JsonResponse
    {
        $status = NotificationStatus::tryFrom((string) $request->input('status', 'read')) ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Unknown status.', ['field' => 'status']);
        $ids = $request->has('ids') ? array_values(array_filter((array) $request->input('ids'), 'is_string')) : null;

        return response()->json(['data' => ['updated' => $inbox->mark($this->actor($request), $status, $ids)]]);
    }

    public function preferences(Request $request, NotificationInbox $inbox): JsonResponse
    {
        return response()->json(['data' => $inbox->preferences($this->actor($request))]);
    }

    public function setPreference(Request $request, NotificationInbox $inbox): JsonResponse
    {
        return $this->respond($inbox->setPreference($this->actor($request), (string) $request->input('event_type'),
            array_values(array_filter((array) $request->input('channels', []), 'is_string')), $request->boolean('is_muted')));
    }

    // ---- Printing ----------------------------------------------------------------------------

    public function print(Request $request, DocumentPrintService $printer, string $type, string $id): Response
    {
        $format = $request->query('format') === 'html' ? 'html' : 'pdf';
        $out = $printer->print($type, $id, $this->actor($request), $format, ['copies' => $request->query('copies', 1)]);

        return response($out['content'], 200, ['Content-Type' => $out['mime'],
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.$out['filename'].'"', 'Cache-Control' => 'private, no-store']);
    }

    public function printItemLabel(Request $request, DocumentPrintService $printer, string $id): Response
    {
        return $this->print($request, $printer, 'item_label', $id);
    }

    private function assertImport(Request $request, ImportBatch $batch): void
    {
        if (! $this->access->can($this->actor($request), "exchange.import.{$batch->import_type->value}")) {
            throw DomainException::notFound('Import');
        }
    }
}
