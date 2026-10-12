<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\IdentityAccess\Services\Access;
use App\Domains\Messaging\Models\OutboxEvent;
use App\Domains\Messaging\Models\WebhookDelivery;
use App\Domains\Messaging\Models\WebhookEndpoint;
use App\Domains\Messaging\Services\OutboxRelay;
use App\Domains\Messaging\Services\WebhookService;
use App\Domains\Platform\Config\ConfigRegistry;
use App\Domains\Platform\Config\ConfigScopeType;
use App\Domains\Platform\Config\PlatformConfig;
use App\Domains\Platform\Config\SetConfigurationValueAction;
use App\Domains\Platform\Models\EnumLabel;
use App\Domains\Platform\Models\Lookup;
use App\Domains\Platform\Models\LookupValue;
use App\Domains\Platform\Models\Template;
use App\Domains\Platform\Templates\DocumentPrintService;
use App\Support\Enums\EnumLabelResolver;
use App\Support\Enums\EnumRegistry;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Administration (Chapter 03): configuration by scope with inheritance, lookups, sandboxed
 * templates with versions and preview, configurable enum labels, webhooks and the outbox.
 * Every change is audited; secrets are write-only.
 */
final class AdminController extends ApiController
{
    public function __construct(
        private readonly ConfigRegistry $registry,
        private readonly PlatformConfig $config,
        private readonly Access $access,
        private readonly AuditRecorder $audit,
    ) {}

    // ---- Configuration ---------------------------------------------------------------------

    public function config(Request $request): JsonResponse
    {
        $scope = array_filter(['warehouse' => $request->query('warehouse_id'), 'branch' => $request->query('branch_id'),
            'category' => $request->query('category_id'), 'item' => $request->query('item_id')]);
        $group = $request->query('group');
        $rows = [];
        foreach ($group ? $this->registry->group((string) $group) : $this->registry->all() as $d) {
            $explain = $this->config->explain($d->key, $scope);
            $rows[] = $d->toArray() + ['effective' => $d->sensitive ? ($explain['source'] === 'default' ? null : '••••••') : $this->presenter()->data($explain['value']),
                'source' => $explain['source'], 'source_scope_id' => $explain['scope_id'],
                'can_manage' => $this->access->can($this->actor($request), $d->managePermission())];
        }

        return response()->json(['data' => $rows, 'meta' => ['groups' => $this->registry->groups()]]);
    }

    public function setConfig(Request $request, string $key): JsonResponse
    {
        $actor = $this->actor($request);
        $definition = $this->registry->get($key);
        if (! $this->access->can($actor, $definition->managePermission())) {
            throw DomainException::permissionDenied($definition->managePermission());
        }
        $scope = ConfigScopeType::tryFrom((string) $request->input('scope_type', 'global')) ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED,
            'Unknown scope type.', ['field' => 'scope_type']);
        $entry = app(SetConfigurationValueAction::class)->execute($key, $request->input('value'), $actor, $scope, $request->input('scope_id'),
            $request->filled('version') ? (int) $request->input('version') : null, $request->input('reason'));

        return response()->json(['data' => ['key' => $key, 'scope_type' => $scope->value, 'scope_id' => $request->input('scope_id'),
            'version' => $entry->version ?? null, 'effective' => $definition->sensitive ? '••••••' : $this->presenter()->data($this->config->get($key))]]);
    }

    // ---- Lookups ---------------------------------------------------------------------------

    public function lookups(): JsonResponse
    {
        return response()->json(['data' => Lookup::query()->orderBy('code')->get()->map(fn (Lookup $l) => $this->presenter()->model($l) + [
            'values' => LookupValue::query()->where('lookup_id', $l->id)->orderBy('sort_order')->orderBy('label')->get()->map(fn ($v) => $this->presenter()->model($v))->all(),
        ])->all()]);
    }

    public function saveLookupValue(Request $request, string $lookupCode): JsonResponse
    {
        $actor = $this->actor($request);
        $lookup = Lookup::query()->where('code', $lookupCode)->firstOrFail();
        $code = (string) $request->input('code');
        if (! preg_match('/^[a-z0-9_]{1,64}$/', $code) || trim((string) $request->input('label')) === '') {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Lookup values need a lowercase code and a label.', ['field' => 'code']);
        }
        $value = LookupValue::query()->firstOrNew(['lookup_id' => $lookup->id, 'code' => $code]);
        $before = $value->exists ? $value->only(['label', 'is_active', 'sort_order']) : null;
        $value->forceFill(['label' => trim((string) $request->input('label')), 'sort_order' => (int) $request->input('sort_order', $value->sort_order ?? 0),
            'is_active' => $request->boolean('is_active', true), 'is_system' => $value->is_system ?? false, 'metadata' => $request->input('metadata')])->save();
        $this->audit->record($actor, 'platform.lookup_value.saved', 'lookup_values', $value->id, $before, $value->only(['label', 'is_active', 'sort_order']));

        return $this->respond($value);
    }

    // ---- Templates -----------------------------------------------------------------------

    public function templates(): JsonResponse
    {
        return response()->json(['data' => Template::query()->orderBy('code')->get(['id', 'code', 'name', 'engine', 'version', 'is_system_default', 'is_active', 'updated_at'])
            ->map(fn ($t) => $this->presenter()->model($t))->all()]);
    }

    public function template(string $id): JsonResponse
    {
        $t = Template::query()->findOrFail($id);

        return response()->json(['data' => $this->presenter()->model($t) + ['versions' => DB::table('template_versions')->where('template_id', $t->id)
            ->orderByDesc('version')->limit(50)->get(['version', 'changed_by', 'created_at'])->all()]]);
    }

    public function saveTemplate(Request $request, string $id): JsonResponse
    {
        $actor = $this->actor($request);
        $body = (string) $request->input('body', '');
        app(DocumentPrintService::class)->renderer()->validate($body);
        if (strlen($body) > 200_000) {
            throw DomainException::of(ErrorCode::PAYLOAD_TOO_LARGE, 'Templates are limited to 200 KB.');
        }
        $t = DB::transaction(function () use ($id, $body, $actor, $request) {
            $t = Template::query()->lockForUpdate()->findOrFail($id);
            DB::table('template_versions')->insert(['id' => (string) Str::uuid7(), 'template_id' => $t->id, 'version' => $t->version, 'body' => $t->body,
                'variables_schema' => $t->variables_schema === null ? null : json_encode($t->variables_schema), 'changed_by' => $actor->id, 'created_at' => now()]);
            $t->forceFill(['body' => $body, 'version' => $t->version + 1, 'is_active' => $request->boolean('is_active', true), 'is_system_default' => false,
                'updated_by' => $actor->id])->save();

            return $t;
        });
        $this->audit->record($actor, 'platform.template.updated', 'templates', $t->id, null, ['code' => $t->code, 'version' => $t->version]);

        return $this->respond($t);
    }

    public function previewTemplate(Request $request): JsonResponse
    {
        $html = app(DocumentPrintService::class)->preview((string) $request->input('body', ''), (array) $request->input('sample', []));

        return response()->json(['data' => ['html' => $html]]);
    }

    // ---- Enum labels ---------------------------------------------------------------------

    public function setEnumLabel(Request $request, EnumRegistry $enums, EnumLabelResolver $labels): JsonResponse
    {
        $actor = $this->actor($request);
        $class = $enums->byCode((string) $request->input('enum_code')) ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Unknown enum.', ['field' => 'enum_code']);
        $class::tryFrom((string) $request->input('case_value')) ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED,
            'Unknown value. Allowed: '.implode(', ', $class::values()).'.', ['field' => 'case_value']);
        $label = trim((string) $request->input('label'));
        if ($label === '' || mb_strlen($label) > 160) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Enter a label (max 160 characters).', ['field' => 'label']);
        }
        $row = EnumLabel::query()->updateOrCreate(['enum_code' => $request->input('enum_code'), 'case_value' => $request->input('case_value'),
            'locale' => (string) $request->input('locale', 'en')], ['label' => $label, 'description' => $request->input('description'), 'updated_by' => $actor->id]);
        $labels->forget();
        $this->audit->record($actor, 'platform.enum_label.updated', 'enum_labels', $row->id, null, $row->only(['enum_code', 'case_value', 'label']));

        return $this->respond($row);
    }

    // ---- Webhooks & outbox --------------------------------------------------------------

    public function webhooks(): JsonResponse
    {
        return response()->json(['data' => WebhookEndpoint::query()->orderBy('name')->get()->map(fn ($e) => array_diff_key($this->presenter()->model($e), ['secret_encrypted' => 1]))->all()]);
    }

    public function registerWebhook(Request $request, WebhookService $webhooks): JsonResponse
    {
        $name = trim((string) $request->input('name'));
        $url = (string) $request->input('url');
        if ($name === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Enter a name and a valid URL.', ['field' => 'url']);
        }
        ['endpoint' => $endpoint, 'secret' => $secret] = $webhooks->register($name, $url, array_values(array_filter((array) $request->input('event_types', ['*']), 'is_string')), $this->actor($request));

        return response()->json(['data' => array_diff_key($this->presenter()->model($endpoint), ['secret_encrypted' => 1]) + ['secret' => $secret]], 201);
    }

    public function rotateWebhookSecret(Request $request, WebhookService $webhooks, string $id): JsonResponse
    {
        return response()->json(['data' => ['secret' => $webhooks->rotateSecret($id, $this->actor($request))]]);
    }

    public function setWebhookActive(Request $request, string $id): JsonResponse
    {
        $endpoint = WebhookEndpoint::query()->findOrFail($id);
        $endpoint->forceFill(['is_active' => $request->boolean('is_active'), 'consecutive_failures' => 0, 'disabled_at' => $request->boolean('is_active') ? null : now(),
            'updated_by' => $this->actor($request)->id])->save();
        $this->audit->record($this->actor($request), 'messaging.webhook.toggled', 'webhook_endpoints', $endpoint->id, null, ['is_active' => $endpoint->is_active]);

        return response()->json(['data' => array_diff_key($this->presenter()->model($endpoint), ['secret_encrypted' => 1])]);
    }

    public function webhookDeliveries(Request $request): JsonResponse
    {
        $page = WebhookDelivery::query()->when($request->filled('status'), fn ($q) => $q->where('status', (string) $request->query('status')))
            ->when($request->filled('endpoint_id'), fn ($q) => $q->where('webhook_endpoint_id', (string) $request->query('endpoint_id')))
            ->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate(50);

        return response()->json(['data' => $this->presenter()->collection($page->items()), 'meta' => ['next_cursor' => $page->nextCursor()?->encode()]]);
    }

    public function retryWebhookDelivery(Request $request, WebhookService $webhooks, string $id): JsonResponse
    {
        return $this->respond($webhooks->retry($id, $this->actor($request)));
    }

    public function outbox(Request $request): JsonResponse
    {
        $page = OutboxEvent::query()->when($request->filled('status'), fn ($q) => $q->where('status', (string) $request->query('status')))
            ->when($request->filled('event_type'), fn ($q) => $q->where('event_type', (string) $request->query('event_type')))
            ->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate(50);

        return response()->json(['data' => $this->presenter()->collection($page->items()), 'meta' => ['next_cursor' => $page->nextCursor()?->encode()]]);
    }

    public function retryOutbox(Request $request, OutboxRelay $relay): JsonResponse
    {
        $ids = array_values(array_filter((array) $request->input('ids', []), fn ($id) => is_string($id) && Str::isUuid($id)));
        $n = $relay->retryDead($ids);
        $this->audit->record($this->actor($request), 'messaging.outbox.retried', 'outbox_events', $ids[0] ?? (string) Str::uuid7(), null, ['count' => $n]);

        return response()->json(['data' => ['requeued' => $n]]);
    }
}
