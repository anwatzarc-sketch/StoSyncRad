<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\Audit\Models\AuditEvent;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Fulfillment\Enums\SalesChannel;
use App\Domains\Fulfillment\Services\SalesOrderWorkflow;
use App\Domains\Fulfillment\Services\ShipmentWorkflow;
use App\Domains\IdentityAccess\Services\Access;
use App\Domains\Platform\Config\PlatformConfig;
use App\Domains\Platform\Enums\BrandAssetKind;
use App\Domains\Platform\Services\BrandKitService;
use App\Domains\Platform\Services\BrandResolver;
use App\Domains\Platform\Services\ConfigBundleService;
use App\Domains\Platform\Services\PlatformHealth;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Platform administration (Chapter 03, 14, 16): brand kits, configuration bundles, detailed health,
 * maintenance mode, audit trail and access review, plus token-authenticated order/carrier intake.
 */
final class PlatformAdminController extends ApiController
{
    public function __construct(private readonly AuditRecorder $audit, private readonly Access $access, private readonly PlatformConfig $config) {}

    public function brand(BrandResolver $brand): JsonResponse
    {
        return response()->json(['data' => $brand->publicProfile() + ['kits' => DB::table('brand_kits')->orderByDesc('version')
            ->get(['id', 'version', 'app_name', 'is_active', 'activated_at', 'created_at'])->map(fn ($k) => (array) $k)->all()]]);
    }

    public function uploadBrandAsset(Request $request, BrandKitService $kits): JsonResponse
    {
        $kind = BrandAssetKind::tryFrom((string) $request->input('kind')) ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED,
            'Allowed: '.implode(', ', BrandAssetKind::values()).'.', ['field' => 'kind']);
        $file = $request->file('file') ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Attach an image.', ['field' => 'file']);

        return response()->json(['data' => ['upload_id' => $kits->storeUpload($file, $kind), 'kind' => $kind->value]], 201);
    }

    public function activateBrand(Request $request, BrandKitService $kits, BrandResolver $brand): JsonResponse
    {
        $kit = $kits->createAndActivate($request->only(['app_name', 'app_short_name', 'tagline', 'legal_footer', 'support_email', 'support_phone', 'support_url',
            'theme', 'locale_defaults', 'assets', 'contrast_override_reason']), $this->actor($request));
        $brand->forget();

        return $this->respond($kit, 201);
    }

    public function exportBundle(Request $request, ConfigBundleService $bundles): JsonResponse
    {
        return response()->json($bundles->export($this->actor($request)), 200, ['Content-Disposition' => 'attachment; filename="config-bundle-'.now()->format('Ymd-His').'.json"']);
    }

    public function importBundle(Request $request, ConfigBundleService $bundles): JsonResponse
    {
        $bundle = $request->hasFile('file') ? json_decode((string) file_get_contents($request->file('file')->getRealPath()), true) : $request->json()->all();

        return response()->json(['data' => $bundles->import((array) $bundle, $this->actor($request), dryRun: $request->boolean('dry_run'))]);
    }

    public function health(PlatformHealth $health): JsonResponse
    {
        $checks = $health->checks();
        $statuses = array_column($checks, 'status');

        return response()->json(['data' => [
            'status' => in_array('fail', $statuses, true) ? 'fail' : (in_array('warn', $statuses, true) ? 'warn' : 'pass'),
            'checks' => $checks,
            'ready' => $health->ready(),
            'reconciliation' => DB::table('reconciliation_runs')->orderByDesc('started_at')->first(['status', 'findings_count', 'started_at']),
        ]]);
    }

    public function maintenance(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        if ($request->boolean('enabled')) {
            $secret = Str::random(32);
            Artisan::call('down', ['--secret' => $secret, '--retry' => 60]);
            $this->audit->record($actor, 'platform.maintenance.enabled', 'platform_setup', (string) Str::uuid7(), null, null, $request->input('reason'));

            return response()->json(['data' => ['maintenance' => true, 'bypass_url' => url('/'.$secret)]]);
        }
        Artisan::call('up');
        $this->audit->record($actor, 'platform.maintenance.disabled', 'platform_setup', (string) Str::uuid7());

        return response()->json(['data' => ['maintenance' => false]]);
    }

    public function auditEvents(Request $request): JsonResponse
    {
        $page = $this->auditQuery($request)->orderByDesc('occurred_at')->orderByDesc('id')->cursorPaginate(100);

        return response()->json(['data' => $this->presenter()->collection($page->items()), 'meta' => ['next_cursor' => $page->nextCursor()?->encode()]]);
    }

    public function exportAuditEvents(Request $request): StreamedResponse
    {
        $query = $this->auditQuery($request)->orderBy('occurred_at')->orderBy('id');
        $this->audit->record($this->actor($request), 'audit.events.exported', 'audit_events', (string) Str::uuid7(), null, $request->query());

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'wb');
            fputcsv($out, ['occurred_at', 'event_type', 'actor_account_id', 'subject_type', 'subject_id', 'reason', 'correlation_id', 'is_failed_attempt'], ',', '"', '');
            foreach ($query->limit(100000)->cursor() as $e) {
                fputcsv($out, [$e->occurred_at?->toIso8601String(), $e->event_type, $e->actor_account_id, $e->subject_type, $e->subject_id, $e->reason, $e->correlation_id,
                    $e->is_failed_attempt ? 'yes' : 'no'], ',', '"', '');
            }
            fclose($out);
        }, 'audit-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function approvals(Request $request): JsonResponse
    {
        $type = (string) $request->query('document_type');
        $id = (string) $request->query('document_id');
        Str::isUuid($id) || throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'document_id must be a UUID.', ['field' => 'document_id']);

        return response()->json(['data' => DB::table('approval_records as a')->leftJoin('user_accounts as u', 'u.id', '=', 'a.actor_account_id')
            ->where('a.document_type', $type)->where('a.document_id', $id)->orderBy('a.acted_at')
            ->get(['a.action', 'a.tier', 'a.comment', 'a.acted_at', 'u.username'])->map(fn ($r) => (array) $r)->all()]);
    }

    public function accessReview(Request $request): StreamedResponse
    {
        $this->audit->record($this->actor($request), 'identity.access_review.exported', 'role_assignments', (string) Str::uuid7());
        $rows = DB::table('role_assignments as ra')->join('user_accounts as u', 'u.id', '=', 'ra.user_account_id')->join('roles as r', 'r.id', '=', 'ra.role_id')
            ->leftJoin('persons as p', 'p.id', '=', 'u.person_id')->orderBy('u.username')->orderBy('r.code')
            ->get(['u.username', 'p.full_name', 'u.account_status', 'u.is_super_admin', 'u.last_login_at', 'r.code as role', 'ra.scope_type', 'ra.scope_id', 'ra.effective_from', 'ra.effective_to']);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'wb');
            fputcsv($out, ['username', 'full_name', 'status', 'super_admin', 'last_login_at', 'role', 'permissions', 'scope_type', 'scope_id', 'effective_from', 'effective_to'], ',', '"', '');
            $perms = DB::table('role_permissions as rp')->join('roles as r', 'r.id', '=', 'rp.role_id')->get(['r.code', 'rp.permission_code'])->groupBy('code');
            foreach ($rows as $r) {
                fputcsv($out, [$r->username, $r->full_name, $r->account_status, $r->is_super_admin ? 'yes' : 'no', $r->last_login_at, $r->role,
                    implode(' ', ($perms[$r->role] ?? collect())->pluck('permission_code')->all()), $r->scope_type, $r->scope_id, $r->effective_from, $r->effective_to], ',', '"', '');
            }
            fclose($out);
        }, 'access-review-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv']);
    }

    /** E-commerce/POS intake for service-account tokens: create + confirm in one call (idempotent by external id). */
    public function intakeOrder(Request $request, SalesOrderWorkflow $orders): JsonResponse
    {
        if (! $this->config->bool('integration.ecommerce.enabled')) {
            throw DomainException::featureDisabled('integration.ecommerce.enabled');
        }
        $actor = $this->actor($request);
        $data = $this->payload($request) + ['channel' => SalesChannel::ECOMMERCE->value];
        try {
            $order = $orders->save($data, $actor);
        } catch (DomainException $e) {
            if ($e->errorCode === ErrorCode::DUPLICATE_RESOURCE) {
                return response()->json(['data' => ['sales_order_id' => $e->details['sales_order_id'], 'document_number' => $e->details['document_number'], 'duplicate' => true]]);
            }
            throw $e;
        }

        return $this->respond($orders->confirm($order->id, $actor), 201);
    }

    /** Carrier status update for service-account tokens (alternative to signed webhooks). */
    public function carrierEventByToken(Request $request, ShipmentWorkflow $shipments): JsonResponse
    {
        $payload = $this->payload($request);
        $shipment = \App\Domains\Fulfillment\Models\Shipment::query()->where('tracking_number', (string) ($payload['tracking_number'] ?? ''))->firstOrFail();

        return $this->respond($shipments->recordEvent($shipment->id, $this->actor($request), $this->idempotencyKey($request), $payload, 'carrier:token'));
    }

    private function auditQuery(Request $request)
    {
        return AuditEvent::query()
            ->when($request->filled('event_type'), fn ($q) => $q->where('event_type', 'like', str_replace(['%', '_'], ['\\%', '\\_'], (string) $request->query('event_type')).'%'))
            ->when($request->filled('subject_type'), fn ($q) => $q->where('subject_type', (string) $request->query('subject_type')))
            ->when($request->filled('subject_id'), fn ($q) => $q->where('subject_id', (string) $request->query('subject_id')))
            ->when($request->filled('actor_id'), fn ($q) => $q->where('actor_account_id', (string) $request->query('actor_id')))
            ->when($request->filled('from'), fn ($q) => $q->where('occurred_at', '>=', (string) $request->query('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('occurred_at', '<=', (string) $request->query('to')));
    }
}
