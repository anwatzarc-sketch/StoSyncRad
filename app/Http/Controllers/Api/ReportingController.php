<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\IdentityAccess\Permissions\PermissionRegistry;
use App\Domains\IdentityAccess\Services\Access;
use App\Domains\Platform\Config\PlatformConfig;
use App\Domains\ReportingEngine\Catalog\DatasetCatalog;
use App\Domains\ReportingEngine\Enums\ReportOutputFormat;
use App\Domains\ReportingEngine\Enums\ReportVisibility;
use App\Domains\ReportingEngine\Models\ReportDefinition;
use App\Domains\ReportingEngine\Models\ReportRun;
use App\Domains\ReportingEngine\Services\ReportDefinitionService;
use App\Domains\ReportingEngine\Services\ReportRunner;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Reporting API (Chapter 10): catalog (only datasets/fields the caller may use, restricted fields
 * flagged), definitions, on-demand web runs, export requests (202 + poll), cancellation and
 * re-authorized downloads through short-lived signed URLs.
 */
final class ReportingController extends ApiController
{
    public function __construct(
        private readonly DatasetCatalog $catalog,
        private readonly Access $access,
        private readonly PlatformConfig $config,
        private readonly ReportDefinitionService $definitions,
        private readonly ReportRunner $runner,
    ) {}

    public function catalog(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $registry = app(PermissionRegistry::class);
        $out = [];
        foreach ($this->catalog->all() as $d) {
            $feature = $d->feature ?? $registry->get($d->permission)?->featureFlag;
            if (($feature !== null && ! $this->config->feature($feature))
                || (! $this->access->hasPermissionAnywhere($actor, $d->permission) && ! ($d->ownColumn && $this->access->hasPermissionAnywhere($actor, preg_replace('/\.read$/', '.read_own', $d->permission))))) {
                continue;
            }
            $row = $d->toArray();
            foreach ($row['fields'] as &$f) {
                $f['allowed'] = $f['required_permission'] === null || $this->access->hasPermissionAnywhere($actor, $f['required_permission']);
            }
            $out[] = $row;
        }

        return response()->json(['data' => $out]);
    }

    public function definitions(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $query = $this->definitions->visibleTo($actor)
            ->when($request->filled('dataset'), fn ($q) => $q->where('dataset_code', (string) $request->query('dataset')))
            ->when($request->query('scope') === 'mine', fn ($q) => $q->where('owner_account_id', $actor->id))
            ->when($request->query('scope') === 'system', fn ($q) => $q->where('is_system_seed', true))
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'ILIKE', '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $request->query('q')).'%'))
            ->orderByDesc('is_system_seed')->orderBy('name');

        return response()->json(['data' => $this->presenter()->collection($query->limit(500)->get())]);
    }

    public function definition(Request $request, string $id): JsonResponse
    {
        $def = ReportDefinition::query()->findOrFail($id);
        $this->definitions->assertCanRead($def, $this->actor($request));

        return $this->respond($def);
    }

    public function createDefinition(Request $request): JsonResponse
    {
        return $this->respond($this->definitions->create($request->only(['name', 'description', 'dataset_code', 'definition', 'tags']), $this->actor($request)), 201);
    }

    public function updateDefinition(Request $request, string $id): JsonResponse
    {
        return $this->respond($this->definitions->update($id, $request->only(['name', 'description', 'dataset_code', 'definition', 'tags', 'change_note']),
            $this->actor($request), $request->filled('version') ? (int) $request->input('version') : null));
    }

    public function cloneDefinition(Request $request, string $id): JsonResponse
    {
        return $this->respond($this->definitions->clone($id, $this->actor($request), $request->input('name')), 201);
    }

    public function shareDefinition(Request $request, string $id): JsonResponse
    {
        $visibility = ReportVisibility::tryFrom((string) $request->input('visibility')) ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED,
            'Allowed: '.implode(', ', ReportVisibility::values()).'.', ['field' => 'visibility']);

        return $this->respond($this->definitions->share($id, $visibility, (array) $request->input('shared_role_ids', []), $this->actor($request)));
    }

    public function archiveDefinition(Request $request, string $id): JsonResponse
    {
        return $this->respond($this->definitions->archive($id, $this->actor($request)));
    }

    /** Run a saved definition (web) — every call queries live data. */
    public function run(Request $request, string $id): JsonResponse
    {
        $def = ReportDefinition::query()->findOrFail($id);

        return $this->respond($this->runner->runWeb($def, $this->actor($request), (array) $request->input('parameters', []),
            (int) $request->input('page', 1), (int) $request->input('per_page', 100)));
    }

    /** Run an unsaved draft (builder live preview, 200-row sample). */
    public function preview(Request $request): JsonResponse
    {
        return $this->respond($this->runner->runWeb(['dataset_code' => (string) $request->input('dataset_code'), 'definition' => (array) $request->input('definition', [])],
            $this->actor($request), (array) $request->input('parameters', []), 1, min(200, (int) $request->input('per_page', 200))));
    }

    public function export(Request $request, string $id): JsonResponse
    {
        $format = ReportOutputFormat::tryFrom((string) ($request->route()->getAction('variant') ?? $request->input('format'))) ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED,
            'Choose pdf, xlsx or csv.', ['field' => 'format']);
        $source = $id === 'draft'
            ? ['dataset_code' => (string) $request->input('dataset_code'), 'definition' => (array) $request->input('definition', [])]
            : ReportDefinition::query()->findOrFail($id);
        $run = $this->runner->requestExport($source, $this->actor($request), $format, (array) $request->input('parameters', []));

        return response()->json(['data' => $this->presenter()->model($run->refresh())], 202, ['Location' => url("/api/v1/report-runs/{$run->id}")]);
    }

    public function runs(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $all = $request->route()->getAction('variant') === 'all' && $this->access->hasPermissionAnywhere($actor, 'reporting.run.read_all');
        $page = ReportRun::query()->when(! $all, fn ($q) => $q->where('runner_account_id', $actor->id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', (string) $request->query('status')))
            ->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate(50);

        $data = array_map(fn (array $r) => array_diff_key($r, ['file_path' => 1, 'backend_pid' => 1, 'authorized_scope_snapshot' => 1]),
            $this->presenter()->collection($page->items()));

        return response()->json(['data' => $data, 'meta' => ['next_cursor' => $page->nextCursor()?->encode()]]);
    }

    public function showRun(Request $request, string $id): JsonResponse
    {
        $actor = $this->actor($request);
        $run = ReportRun::query()->findOrFail($id);
        if ($run->runner_account_id !== $actor->id && ! $this->access->hasPermissionAnywhere($actor, 'reporting.run.read_all')) {
            throw DomainException::notFound('Report run');
        }
        $data = $this->presenter()->model($run);
        unset($data['file_path'], $data['backend_pid'], $data['authorized_scope_snapshot']);
        if ($run->file_path !== null && $run->runner_account_id === $actor->id) {
            $data['download_url'] = URL::temporarySignedRoute('report_runs.download', now()->addMinutes(10), ['id' => $run->id]);
        }

        return response()->json(['data' => $data]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        return $this->respond($this->runner->cancel($id, $this->actor($request)));
    }

    /** Signed + authenticated + re-authorized download. */
    public function download(Request $request, string $id): BinaryFileResponse
    {
        $file = $this->runner->download($id, $this->actor($request));

        return response()->download($file['path'], $file['filename'], ['Content-Type' => $file['mime'], 'Cache-Control' => 'private, no-store']);
    }
}
