<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Services;

use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\DataExchange\Enums\ExportFileKind;
use App\Domains\DataExchange\Models\ExportFile;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Services\Access;
use App\Domains\Messaging\Services\Outbox;
use App\Domains\Platform\Config\PlatformConfig;
use App\Domains\ReportingEngine\Enums\ReportOutputFormat as Format;
use App\Domains\ReportingEngine\Enums\ReportRunStatus as S;
use App\Domains\ReportingEngine\Enums\ReportSlotKind;
use App\Domains\ReportingEngine\Jobs\RunReportExport;
use App\Domains\ReportingEngine\Models\ReportDefinition;
use App\Domains\ReportingEngine\Models\ReportRun;
use App\Domains\ReportingEngine\Output\CsvReportWriter;
use App\Domains\ReportingEngine\Output\PdfReportWriter;
use App\Domains\ReportingEngine\Output\ReportWriter;
use App\Domains\ReportingEngine\Output\XlsxReportWriter;
use App\Domains\ReportingEngine\Query\CompiledQuery;
use App\Domains\ReportingEngine\Query\QueryCompiler;
use App\Support\Database\SqlError;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Http\Correlation;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * On-demand report execution (Chapter 10 §10.5–10.7). Web runs execute synchronously on the
 * read-only reporting connection; exports are user-initiated jobs streaming an unbuffered result from
 * a REPEATABLE READ, READ ONLY transaction (one consistent snapshot) into a writer. Runs store
 * metadata only — never results.
 */
final class ReportRunner
{
    public const CONNECTION = 'mysql_reporting';

    public function __construct(
        private readonly QueryCompiler $compiler,
        private readonly PlatformConfig $config,
        private readonly Access $access,
        private readonly ExecutionSlots $slots,
        private readonly AuditRecorder $audit,
        private readonly ReportDefinitionService $definitions,
    ) {}

    /**
     * @param  ReportDefinition|array{dataset_code: string, definition: array}  $source  saved definition or an ad-hoc draft
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function runWeb(ReportDefinition|array $source, UserAccount $runner, array $params = [], int $page = 1, int $perPage = 100): array
    {
        [$dataset, $definition, $saved] = $this->resolve($source, $runner);
        $this->guard($runner, Format::WEB);
        $max = $this->config->int('reporting.max_sync_rows');
        $perPage = max(1, min($perPage, 1000));
        $offset = max(0, ($page - 1) * $perPage);
        if ($offset >= $max) {
            throw DomainException::of(ErrorCode::REPORT_LIMIT_EXCEEDED, "Web results are limited to $max rows; export the report for more.");
        }
        $compiled = $this->compiler->compile($dataset, $definition, $runner, $params, min($perPage, $max - $offset), $offset);
        $run = $this->startRun($saved, $dataset, $runner, Format::WEB, $params, $compiled, S::RUNNING);
        $timeout = $this->config->int('reporting.interactive_timeout_seconds');
        $slot = $this->slots->acquire(ReportSlotKind::INTERACTIVE, $run->id, $timeout);
        $started = hrtime(true);
        try {
            $rows = $this->reporting()->transaction(function (Connection $db) use ($compiled, $timeout, $run) {
                $this->prepareSession($db, $timeout, $run);
                $this->costGate($compiled);

                return array_map(fn ($r) => $this->normalize((array) $r, $compiled), $db->select($compiled->sql, $compiled->bindings));
            });
        } catch (\Throwable $e) {
            $this->fail($run, $e, $started);
            throw $this->translate($e);
        } finally {
            $this->slots->release($slot);
        }
        $hasMore = count($rows) > $compiled->limit;
        $rows = array_slice($rows, 0, $compiled->limit);
        $truncated = $hasMore && $offset + $compiled->limit >= $max;
        $run->forceFill(['status' => S::COMPLETED, 'finished_at' => now(), 'duration_ms' => $this->ms($started), 'row_count' => count($rows),
            'truncated' => $truncated, 'backend_pid' => null])->save();
        $this->audit->record($runner, 'reporting.report.run', 'report_runs', $run->id, null, ['dataset' => $dataset, 'rows' => count($rows),
            'definition_id' => $saved?->id]);

        return [
            'run_id' => $run->id, 'columns' => $compiled->columns, 'rows' => $rows, 'page' => $page, 'per_page' => $perPage,
            'has_more' => $hasMore && ! $truncated, 'truncated' => $truncated, 'row_cap' => $max, 'executed_at' => $run->started_at?->toIso8601String(),
            'duration_ms' => $run->duration_ms, 'definition_version' => $saved?->definition_version, 'dataset' => $dataset,
        ];
    }

    /** Queue a PDF/XLSX/CSV export (`202 Accepted`; poll the run). */
    public function requestExport(ReportDefinition|array $source, UserAccount $runner, Format $format, array $params = []): ReportRun
    {
        if ($format === Format::WEB) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Choose pdf, xlsx or csv for an export.', ['field' => 'format']);
        }
        [$dataset, $definition, $saved] = $this->resolve($source, $runner);
        $this->guard($runner, $format);
        $compiled = $this->compiler->compile($dataset, $definition, $runner, $params, 1);   // validate now; the job recompiles
        $run = $this->startRun($saved, $dataset, $runner, $format, $params, $compiled, S::QUEUED);
        if ($saved === null) {
            $run->forceFill(['parameters' => ['__definition' => $definition, '__dataset' => $dataset] + $params])->save();
        }
        RunReportExport::dispatch($run->id)->onQueue('reporting');

        return $run;
    }

    /** Worker side of an export (RunReportExport job). */
    public function executeExport(string $runId): ReportRun
    {
        $run = ReportRun::query()->findOrFail($runId);
        if ($run->status !== S::QUEUED) {
            return $run;   // cancelled or already handled (idempotent)
        }
        $runner = UserAccount::query()->findOrFail($run->runner_account_id);
        $started = hrtime(true);
        $slot = null;
        $path = null;
        try {
            $this->guard($runner, $run->output_format, countRun: false);   // permissions may have changed since queuing
            $params = (array) $run->parameters;
            $saved = null;
            if ($run->report_definition_id !== null) {
                $saved = ReportDefinition::query()->findOrFail($run->report_definition_id);
                $this->definitions->assertCanRead($saved, $runner);
                [$dataset, $definition] = [$saved->dataset_code, $saved->definition];
            } else {
                [$dataset, $definition] = [$params['__dataset'], $params['__definition']];
                unset($params['__dataset'], $params['__definition']);
            }
            $cap = $this->config->int('reporting.max_export_rows');
            $compiled = $this->compiler->compile($dataset, $definition, $runner, $params, PHP_INT_MAX >> 1);
            $timeout = $this->config->int('reporting.export_timeout_seconds');
            $slot = $this->slots->acquire(ReportSlotKind::EXPORT, $run->id, $timeout);
            $run->forceFill(['status' => S::RUNNING, 'started_at' => now()])->save();
            $writer = $this->writer($run->output_format);
            $name = $saved->name ?? Str::headline($dataset);
            $relative = "reports/{$run->id}/".Str::slug($name).'-'.now()->format('Ymd-His').'.'.$writer->extension();
            $disk = Storage::disk('private');
            $disk->makeDirectory("reports/{$run->id}");
            $path = $disk->path($relative);
            $writer->open($path, $compiled->columns, [
                'report_name' => $name, 'definition_version' => $saved?->definition_version, 'dataset' => $dataset,
                'run_at' => now()->toIso8601String(), 'runner' => $runner->displayName(), 'parameters' => $params,
                'filters' => $definition['filters'] ?? null, 'layout' => $definition['layout'] ?? null,
            ]);
            [$count, $truncated] = $this->stream($compiled, $writer, $cap, $timeout, $run);
            $writer->close($count, $truncated);
            $size = filesize($path) ?: 0;
            if ($size > $this->config->int('reporting.max_file_mb') * 1024 * 1024) {
                @unlink($path);
                throw DomainException::of(ErrorCode::REPORT_LIMIT_EXCEEDED, 'The export is larger than the configured maximum file size; narrow the filters.');
            }
            $expires = now()->addHours($this->config->int('reporting.export_retention_hours'));
            ExportFile::query()->forceCreate(['kind' => ExportFileKind::REPORT, 'disk' => 'private', 'path' => $relative, 'filename' => basename($relative),
                'mime_type' => $writer->mimeType(), 'size' => $size, 'owner_account_id' => $runner->id, 'report_run_id' => $run->id, 'expires_at' => $expires]);
            $run->forceFill(['status' => S::COMPLETED, 'finished_at' => now(), 'duration_ms' => $this->ms($started), 'row_count' => $count,
                'truncated' => $truncated, 'file_path' => $relative, 'file_size' => $size, 'file_expires_at' => $expires, 'backend_pid' => null])->save();
            $this->audit->record($runner, 'reporting.report.exported', 'report_runs', $run->id, null,
                ['format' => $run->output_format->value, 'rows' => $count, 'truncated' => $truncated]);
            app(Outbox::class)->publish('reporting.report.completed', 'report_runs', $run->id,
                ['report_name' => $name, 'runner_account_id' => $runner->id, 'format' => $run->output_format->value, 'rows' => $count]);

            return $run;
        } catch (\Throwable $e) {
            if ($path !== null && is_file($path)) {
                @unlink($path);
            }
            $this->fail($run, $e, $started);
            app(Outbox::class)->publish('reporting.report.failed', 'report_runs', $run->id,
                ['report_name' => $name ?? $run->dataset_code, 'runner_account_id' => $run->runner_account_id, 'error' => $run->refresh()->error_code]);

            return $run->refresh();
        } finally {
            if ($slot !== null) {
                $this->slots->release($slot);
            }
        }
    }

    public function cancel(string $runId, UserAccount $actor): ReportRun
    {
        $run = ReportRun::query()->findOrFail($runId);
        $own = $run->runner_account_id === $actor->id && $this->access->can($actor, 'reporting.report.cancel_own');
        if (! $own && ! $this->access->can($actor, 'reporting.run.read_all')) {
            throw DomainException::permissionDenied('reporting.report.cancel_own');
        }
        if (! in_array($run->status, [S::QUEUED, S::RUNNING], true)) {
            return $run;
        }
        if ($run->backend_pid !== null) {
            try {
                // Stops the running statement; the reporting account may kill its own connections' queries.
                $this->reporting()->statement('KILL QUERY '.(int) $run->backend_pid);
            } catch (QueryException) {
                // the statement may have finished in the meantime
            }
        }
        $run->forceFill(['status' => S::CANCELLED, 'finished_at' => now(), 'error_code' => 'CANCELLED'])->save();
        $this->audit->record($actor, 'reporting.report.cancelled', 'report_runs', $run->id);

        return $run;
    }

    /**
     * Re-authorize and return the export for download (signed route).
     *
     * @return array{path: string, filename: string, mime: string}
     */
    public function download(string $runId, UserAccount $actor): array
    {
        $run = ReportRun::query()->findOrFail($runId);
        if ($run->runner_account_id !== $actor->id) {
            throw DomainException::permissionDenied('reporting.report.run');
        }
        $this->guard($actor, $run->output_format, countRun: false);
        $file = ExportFile::query()->where('report_run_id', $run->id)->first();
        if ($run->status !== S::COMPLETED || $file === null || $file->expires_at->isPast() || ! Storage::disk($file->disk)->exists($file->path)) {
            throw DomainException::of(ErrorCode::NOT_FOUND, 'This export has expired or is not available.');
        }
        $file->forceFill(['download_count' => $file->download_count + 1, 'last_downloaded_at' => now()])->save();
        $this->audit->record($actor, 'reporting.report.downloaded', 'report_runs', $run->id, null, ['file' => $file->filename]);

        return ['path' => Storage::disk($file->disk)->path($file->path), 'filename' => $file->filename, 'mime' => $file->mime_type];
    }

    /** Purge expired export files (scheduler). */
    public function purgeExpired(): int
    {
        $n = 0;
        foreach (ExportFile::query()->where('expires_at', '<', now())->orderBy('id')->limit(1000)->get() as $file) {
            Storage::disk($file->disk)->delete($file->path);
            if ($file->report_run_id !== null) {
                ReportRun::query()->whereKey($file->report_run_id)->update(['status' => S::EXPIRED->value, 'file_path' => null, 'updated_at' => now()]);
            }
            $file->delete();
            $n++;
        }

        return $n;
    }

    /** @return array{0: string, 1: array, 2: ?ReportDefinition} */
    private function resolve(ReportDefinition|array $source, UserAccount $runner): array
    {
        if ($source instanceof ReportDefinition) {
            $this->definitions->assertCanRead($source, $runner);
            if (! $source->is_active) {
                throw DomainException::of(ErrorCode::NOT_FOUND, 'This report has been archived.');
            }

            return [$source->dataset_code, (array) $source->definition, $source];
        }

        return [(string) ($source['dataset_code'] ?? ''), (array) ($source['definition'] ?? []), null];
    }

    private function guard(UserAccount $runner, Format $format, bool $countRun = true): void
    {
        $permission = match ($format) {
            Format::WEB => 'reporting.report.run', Format::PDF => 'reporting.report.export_pdf',
            Format::XLSX => 'reporting.report.export_xlsx', Format::CSV => 'reporting.report.export_csv',
        };
        if (! $this->access->can($runner, $permission)) {
            throw DomainException::permissionDenied($permission);
        }
        if ($countRun) {
            $limit = $this->config->int('reporting.runs_per_account_per_hour');
            if (ReportRun::query()->where('runner_account_id', $runner->id)->where('created_at', '>', now()->subHour())->count() >= $limit) {
                throw DomainException::of(ErrorCode::REPORT_LIMIT_EXCEEDED, "You have reached the limit of $limit report runs per hour.");
            }
        }
    }

    private function startRun(?ReportDefinition $saved, string $dataset, UserAccount $runner, Format $format, array $params, CompiledQuery $compiled, S $status): ReportRun
    {
        return ReportRun::query()->forceCreate([
            'report_definition_id' => $saved?->id, 'definition_version' => $saved?->definition_version, 'dataset_code' => $dataset,
            'runner_account_id' => $runner->id, 'output_format' => $format, 'parameters' => $params ?: null,
            'authorized_scope_snapshot' => $compiled->scope, 'status' => $status, 'started_at' => $status === S::RUNNING ? now() : null,
            'correlation_id' => Correlation::id(app()->runningInConsole() ? null : request()),
        ]);
    }

    /** The reporting session is read-only already (set on connect); this adds the run's time limits. */
    private function prepareSession(Connection $db, int $timeoutSeconds, ReportRun $run): void
    {
        $db->statement('SET SESSION max_execution_time = '.($timeoutSeconds * 1000).', SESSION innodb_lock_wait_timeout = 2');
        $pid = (int) $db->selectOne('SELECT CONNECTION_ID() AS pid')->pid;
        ReportRun::query()->whereKey($run->id)->update(['backend_pid' => $pid]);
    }

    /**
     * Reject runs whose estimated cost exceeds the threshold. MySQL only explains a view for an account
     * that can also read the tables beneath it, which the reporting account deliberately cannot; the
     * runtime account (SELECT and SHOW VIEW on the reporting views) produces the plan instead. EXPLAIN
     * returns a plan, never rows.
     */
    private function costGate(CompiledQuery $q): void
    {
        $threshold = $this->config->int('reporting.explain_cost_threshold');
        if ($threshold <= 0) {
            return;
        }
        $plan = json_decode((string) DB::connection()->selectOne('EXPLAIN FORMAT=JSON '.$q->sql, $q->bindings)->EXPLAIN, true);
        $cost = (float) ($plan['query_block']['cost_info']['query_cost'] ?? 0);
        if ($cost > $threshold) {
            throw DomainException::of(ErrorCode::REPORT_TOO_EXPENSIVE, 'This report would scan too much data. Add filters (date range, warehouse, item) or group the results.',
                ['estimated_cost' => (int) $cost, 'threshold' => $threshold]);
        }
    }

    /** @return array{0: int, 1: bool} [rows written, truncated] */
    private function stream(CompiledQuery $q, ReportWriter $writer, int $cap, int $timeout, ReportRun $run): array
    {
        $db = $this->reporting();
        // SET TRANSACTION (without SESSION) applies to the next transaction only, so it precedes BEGIN.
        $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');

        return $db->transaction(function (Connection $db) use ($q, $writer, $cap, $timeout, $run) {
            $this->prepareSession($db, $timeout, $run);
            $this->costGate($q);
            // Unbuffered: rows stream from the server instead of being loaded into memory. Fetching at
            // most cap + 1 rows means a truncated export never has to drain a huge result.
            $pdo = $db->getPdo();
            $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            try {
                $count = 0;
                foreach ($db->cursor($q->unpaged().' LIMIT '.($cap + 1), $q->bindings) as $row) {
                    if ($count >= $cap) {
                        return [$count, true];
                    }
                    $writer->write($this->normalize((array) $row, $q));
                    $count++;
                    if ($count % 1000 === 0 && ReportRun::query()->whereKey($run->id)->value('status') === S::CANCELLED->value) {
                        throw DomainException::of(ErrorCode::OPERATION_TIMEOUT, 'The export was cancelled.');
                    }
                }

                return [$count, false];
            } finally {
                $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            }
        });
    }

    /**
     * MySQL returns booleans as 0/1; report output keeps them as true/false.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalize(array $row, CompiledQuery $q): array
    {
        foreach ($q->columns as $column) {
            if ($column['type'] === 'boolean' && isset($row[$column['key']])) {
                $row[$column['key']] = (bool) $row[$column['key']];
            }
        }

        return $row;
    }

    private function writer(Format $format): ReportWriter
    {
        return match ($format) {
            Format::CSV => app(CsvReportWriter::class), Format::XLSX => app(XlsxReportWriter::class), Format::PDF => app(PdfReportWriter::class),
            Format::WEB => throw new \LogicException('No writer for web output.'),
        };
    }

    private function fail(ReportRun $run, \Throwable $e, int $started): void
    {
        $code = $e instanceof DomainException ? $e->errorCode->value : ((SqlError::isStatementTimeout($e) || SqlError::isCancelled($e)) ? 'OPERATION_TIMEOUT' : 'INTERNAL_ERROR');
        $status = $e instanceof DomainException && in_array($e->errorCode, [ErrorCode::REPORT_TOO_EXPENSIVE, ErrorCode::REPORT_FIELD_FORBIDDEN,
            ErrorCode::PERMISSION_DENIED, ErrorCode::REPORT_CAPACITY], true) ? S::REJECTED : S::FAILED;
        if (ReportRun::query()->whereKey($run->id)->value('status') === S::CANCELLED->value) {
            $status = S::CANCELLED;
        }
        $run->forceFill(['status' => $status, 'finished_at' => now(), 'duration_ms' => $this->ms($started), 'error_code' => $code,
            'error_message' => mb_substr($e->getMessage(), 0, 1000), 'backend_pid' => null])->save();
    }

    private function translate(\Throwable $e): \Throwable
    {
        if (SqlError::isStatementTimeout($e) || SqlError::isCancelled($e)) {
            return DomainException::of(ErrorCode::OPERATION_TIMEOUT, 'The report took too long and was stopped. Add filters or group the results.');
        }

        return $e;
    }

    private function reporting(): Connection
    {
        return DB::connection(self::CONNECTION);
    }

    private function ms(int $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
