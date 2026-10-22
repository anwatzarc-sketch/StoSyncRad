<?php

declare(strict_types=1);

namespace App\Domains\DataExchange\Services;

use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\DataExchange\Enums\ExportFileKind;
use App\Domains\DataExchange\Enums\ImportBatchStatus as S;
use App\Domains\DataExchange\Enums\ImportRowStatus as R;
use App\Domains\DataExchange\Enums\ImportType;
use App\Domains\DataExchange\Jobs\ProcessImportBatch;
use App\Domains\DataExchange\Models\ExportFile;
use App\Domains\DataExchange\Models\ImportBatch;
use App\Domains\DataExchange\Models\ImportRow;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Services\Access;
use App\Domains\Messaging\Services\Outbox;
use App\Domains\Platform\Config\PlatformConfig;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Schema\PropertyType;
use App\Support\Schema\SchemaValidator;
use App\Support\Schema\ValidationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Imports (Chapter 11 §11.9): upload → structure validation → per-row validation with the same
 * property schemas as the API (errors by row/field) → preview → confirm → background processing in
 * chunks → result file → audit. Re-uploading the same file (checksum) returns the existing batch.
 */
final class ImportService
{
    public function __construct(
        private readonly ImportHandlers $handlers,
        private readonly SchemaValidator $validator,
        private readonly Access $access,
        private readonly PlatformConfig $config,
        private readonly AuditRecorder $audit,
        private readonly Outbox $outbox,
    ) {}

    public function upload(ImportType $type, string $localPath, string $originalName, UserAccount $actor, array $options = []): ImportBatch
    {
        $this->authorize($actor, $type);
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (! in_array($ext, ['csv', 'xlsx'], true)) {
            throw DomainException::of(ErrorCode::UNSUPPORTED_MEDIA_TYPE, 'Upload a .csv or .xlsx file.', ['field' => 'file']);
        }
        $checksum = hash_file('sha256', $localPath);
        $existing = ImportBatch::query()->where('import_type', $type->value)->where('file_checksum', $checksum)
            ->whereNotIn('status', [S::FAILED->value, S::CANCELLED->value])->first();
        if ($existing !== null) {
            return $existing;   // idempotent re-upload
        }
        $batch = ImportBatch::query()->forceCreate(['import_type' => $type, 'status' => S::VALIDATING, 'file_path' => '', 'original_filename' => mb_substr($originalName, 0, 255),
            'file_checksum' => $checksum, 'options' => $options ?: null, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
        $relative = "imports/{$batch->id}/source.$ext";
        Storage::disk('private')->put($relative, (string) file_get_contents($localPath));
        $batch->forceFill(['file_path' => $relative])->save();

        try {
            $this->validateFile($batch, Storage::disk('private')->path($relative), $ext, $actor);
        } catch (DomainException $e) {
            $batch->forceFill(['status' => S::FAILED, 'error' => $e->getMessage(), 'finished_at' => now()])->save();
            throw $e;
        }
        $this->audit->record($actor, 'exchange.import.uploaded', 'import_batches', $batch->id, null,
            ['type' => $type->value, 'rows' => $batch->row_count, 'invalid' => $batch->invalid_count]);

        return $batch->refresh();
    }

    public function confirm(string $batchId, UserAccount $actor): ImportBatch
    {
        $batch = DB::transaction(function () use ($batchId, $actor) {
            $batch = ImportBatch::query()->lockForUpdate()->findOrFail($batchId);
            $this->authorize($actor, $batch->import_type);
            if ($batch->status !== S::PREVIEWED) {
                throw DomainException::of(ErrorCode::INVALID_STATE_TRANSITION, "The import is {$batch->status->value}; only previewed imports can be confirmed.");
            }
            if ($batch->valid_count === 0) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'There are no valid rows to import.');
            }
            $batch->forceFill(['status' => S::CONFIRMED, 'confirmed_by' => $actor->id, 'confirmed_at' => now()])->save();

            return $batch;
        });
        $this->audit->record($actor, 'exchange.import.confirmed', 'import_batches', $batch->id);
        ProcessImportBatch::dispatch($batch->id)->onQueue('imports');

        return $batch->refresh();
    }

    public function cancel(string $batchId, UserAccount $actor): ImportBatch
    {
        $batch = ImportBatch::query()->findOrFail($batchId);
        $this->authorize($actor, $batch->import_type);
        if (! in_array($batch->status, [S::UPLOADED, S::VALIDATING, S::PREVIEWED], true)) {
            throw DomainException::of(ErrorCode::INVALID_STATE_TRANSITION, 'Only imports that have not started can be cancelled.');
        }
        $batch->forceFill(['status' => S::CANCELLED, 'finished_at' => now()])->save();

        return $batch;
    }

    /** Worker: import valid rows in chunks; each row commits or fails on its own. */
    public function process(string $batchId): ImportBatch
    {
        $batch = ImportBatch::query()->findOrFail($batchId);
        if ($batch->status !== S::CONFIRMED) {
            return $batch;
        }
        $actor = UserAccount::query()->findOrFail($batch->confirmed_by);
        $batch->forceFill(['status' => S::PROCESSING, 'started_at' => now()])->save();
        $imported = 0;
        try {
            if ($batch->import_type === ImportType::OPENING_BALANCES) {
                $rows = ImportRow::query()->where('import_batch_id', $batch->id)->where('status', R::VALID->value)->orderBy('row_number')->get();
                $docs = $this->handlers->openingBalances($rows->map(fn ($r) => $r->data)->all(), $actor);
                ImportRow::query()->whereIn('id', $rows->pluck('id'))->update(['status' => R::IMPORTED->value, 'updated_at' => now()]);
                $imported = $rows->count();
                $batch->forceFill(['options' => (array) $batch->options + ['opening_balance_document_ids' => $docs]])->save();
            } else {
                ImportRow::query()->where('import_batch_id', $batch->id)->where('status', R::VALID->value)->orderBy('row_number')
                    ->chunkById($this->config->int('exchange.import.chunk_size'), function ($chunk) use ($batch, $actor, &$imported) {
                        foreach ($chunk as $row) {
                            try {
                                $id = DB::transaction(fn () => $this->handlers->import($batch->import_type, $row->data, $actor));
                                $row->forceFill(['status' => R::IMPORTED, 'result_id' => $id])->save();
                                $imported++;
                            } catch (\Throwable $e) {
                                $row->forceFill(['status' => R::INVALID, 'errors' => $this->errors($e)])->save();
                            }
                        }
                        $batch->forceFill(['imported_count' => $imported])->save();
                    });
            }
            $invalid = ImportRow::query()->where('import_batch_id', $batch->id)->where('status', R::INVALID->value)->count();
            $batch->forceFill(['status' => $invalid > 0 ? S::COMPLETED_WITH_ERRORS : S::COMPLETED, 'imported_count' => $imported,
                'invalid_count' => $invalid, 'finished_at' => now()])->save();
        } catch (\Throwable $e) {
            $batch->forceFill(['status' => S::FAILED, 'error' => mb_substr($e->getMessage(), 0, 2000), 'finished_at' => now()])->save();
        }
        $this->audit->record($actor, 'exchange.import.completed', 'import_batches', $batch->id, null,
            ['status' => $batch->status->value, 'imported' => $batch->imported_count, 'invalid' => $batch->invalid_count]);
        $this->outbox->publish('exchange.import.completed', 'import_batches', $batch->id, ['filename' => $batch->original_filename,
            'imported' => $batch->imported_count, 'invalid' => $batch->invalid_count, 'created_by' => $batch->created_by, 'status' => $batch->status->value]);

        return $batch->refresh();
    }

    /** CSV of rejected rows with their errors (private, expiring). */
    public function errorFile(string $batchId, UserAccount $actor): ExportFile
    {
        $batch = ImportBatch::query()->findOrFail($batchId);
        $this->authorize($actor, $batch->import_type);
        $spec = $this->handlers->spec($batch->import_type);
        $relative = "imports/{$batch->id}/errors-".now()->format('YmdHis').'.csv';
        $path = Storage::disk('private')->path($relative);
        Storage::disk('private')->makeDirectory("imports/{$batch->id}");
        $fh = fopen($path, 'wb');
        fputcsv($fh, array_merge(['row'], $spec['columns'], ['errors']), ',', '"', '');
        foreach (ImportRow::query()->where('import_batch_id', $batch->id)->where('status', R::INVALID->value)->orderBy('row_number')->cursor() as $row) {
            $raw = (array) ($row->data['__raw'] ?? $row->data);
            fputcsv($fh, array_merge([$row->row_number], array_map(fn ($c) => is_array($raw[$c] ?? null) ? implode(';', $raw[$c]) : ($raw[$c] ?? ''), $spec['columns']),
                [collect((array) $row->errors)->map(fn ($m, $f) => "$f: ".(is_array($m) ? implode(' ', $m) : $m))->implode(' | ')]), ',', '"', '');
        }
        fclose($fh);

        return ExportFile::query()->forceCreate(['kind' => ExportFileKind::IMPORT_ERRORS, 'disk' => 'private', 'path' => $relative, 'filename' => basename($relative),
            'mime_type' => 'text/csv', 'size' => filesize($path) ?: 0, 'owner_account_id' => $actor->id, 'expires_at' => now()->addDays(7)]);
    }

    /** Header-only template for a type. */
    public function template(ImportType $type): string
    {
        return implode(',', $this->handlers->spec($type)['columns'])."\n";
    }

    private function validateFile(ImportBatch $batch, string $path, string $ext, UserAccount $actor): void
    {
        $type = $batch->import_type;
        $spec = $this->handlers->spec($type);
        $reader = $ext === 'xlsx' ? new XlsxReader : new CsvReader;
        $reader->open($path);
        $header = null;
        $codesInFile = [];
        $n = 0;
        $valid = 0;
        $invalid = 0;
        $max = $this->config->int('exchange.import.max_rows');
        $properties = $spec['schema'] !== null ? $spec['schema']::properties() : [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $sheetRow) {
                    $cells = array_map(fn ($v) => $this->cell($v), $sheetRow->toArray());
                    if ($header === null) {
                        $header = array_map(fn ($h) => Str::snake(trim((string) $h)), $cells);
                        $missing = array_diff($spec['required'], $header);
                        $unknown = array_diff(array_filter($header), $spec['columns']);
                        if ($missing !== [] || $unknown !== []) {
                            throw DomainException::of(ErrorCode::VALIDATION_FAILED, trim(($missing ? 'Missing columns: '.implode(', ', $missing).'. ' : '')
                                .($unknown ? 'Unknown columns: '.implode(', ', $unknown).'. ' : '')).' Download the template for the expected layout.',
                                ['missing' => array_values($missing), 'unknown' => array_values($unknown)]);
                        }

                        continue;
                    }
                    if (array_filter($cells, fn ($c) => $c !== null) === []) {
                        continue;   // blank line
                    }
                    if (++$n > $max) {
                        throw DomainException::of(ErrorCode::PAYLOAD_TOO_LARGE, "Imports are limited to $max rows.");
                    }
                    $raw = [];
                    foreach ($header as $i => $col) {
                        if ($col === '') {
                            continue;
                        }
                        $value = $cells[$i] ?? null;
                        if ($value !== null && in_array($col, $spec['lists'], true)) {
                            $value = array_values(array_filter(array_map('trim', explode(';', (string) $value)), fn ($x) => $x !== ''));
                        } elseif ($value !== null && ($properties[$col] ?? null)?->type === PropertyType::BOOLEAN) {
                            $value = match (strtolower((string) $value)) {
                                'yes', 'y', 'true', '1' => true, 'no', 'n', 'false', '0' => false, default => $value
                            };
                        }
                        $raw[$col] = $value;
                    }
                    [$payload, $errors] = $this->handlers->map($type, array_filter($raw, fn ($v) => $v !== null), $codesInFile);
                    if (isset($raw['code'])) {
                        $codesInFile[] = (string) $raw['code'];
                    }
                    if ($errors === [] && $spec['schema'] !== null) {
                        $keep = ['__existing_id', '__parent_code', 'warehouse_id', 'cutoff_date', 'variant_sku'];
                        $check = array_diff_key($payload, array_flip($keep));
                        try {
                            $validated = $this->validator->validate($spec['schema'], $check, new ValidationContext(
                                $this->handlers->operation($type, $payload), fn (string $p) => $this->access->can($actor, $p)));
                            $payload = $validated + array_intersect_key($payload, array_flip($keep));
                        } catch (ValidationException $e) {
                            $errors = $e->errors();
                        }
                    }
                    $ok = $errors === [];
                    $ok ? $valid++ : $invalid++;
                    ImportRow::query()->forceCreate(['import_batch_id' => $batch->id, 'row_number' => $n, 'data' => $payload + ['__raw' => $raw],
                        'status' => $ok ? R::VALID : R::INVALID, 'errors' => $ok ? null : $errors]);
                }
                break;   // first sheet only
            }
        } finally {
            $reader->close();
        }
        if ($header === null) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The file is empty.');
        }
        // __raw is kept only for the error file; strip it from valid rows before processing.
        DB::statement("UPDATE import_rows SET data = JSON_REMOVE(data, '$.__raw') WHERE import_batch_id = ? AND status = ?", [$batch->id, R::VALID->value]);
        $batch->forceFill(['status' => S::PREVIEWED, 'row_count' => $n, 'valid_count' => $valid, 'invalid_count' => $invalid])->save();
    }

    private function cell(mixed $v): mixed
    {
        if ($v instanceof \DateTimeInterface) {
            return $v->format('H:i:s') === '00:00:00' ? $v->format('Y-m-d') : $v->format(DATE_ATOM);
        }
        if (is_float($v)) {
            return rtrim(rtrim(number_format($v, 8, '.', ''), '0'), '.');
        }
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_string($v)) {
            $v = trim($v);

            return $v === '' ? null : $v;
        }

        return $v;
    }

    /** @return array<string, array<int, string>|string> */
    private function errors(\Throwable $e): array
    {
        if ($e instanceof ValidationException) {
            return $e->errors();
        }
        if ($e instanceof DomainException) {
            return [($e->details['field'] ?? 'row') => [$e->getMessage()]];
        }

        return ['row' => [mb_substr($e->getMessage(), 0, 300)]];
    }

    private function authorize(UserAccount $actor, ImportType $type): void
    {
        if (! $this->access->can($actor, "exchange.import.{$type->value}")) {
            throw DomainException::permissionDenied("exchange.import.{$type->value}");
        }
    }
}
