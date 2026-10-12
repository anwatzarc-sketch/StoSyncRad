<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\IdentityAccess\Permissions\PermissionRegistry;
use App\Domains\IdentityAccess\Services\Access;
use App\Domains\Messaging\Services\NotificationInbox;
use App\Domains\Platform\Config\ConfigRegistry;
use App\Domains\Platform\Config\PlatformConfig;
use App\Domains\Platform\Services\BrandResolver;
use App\Domains\Platform\Services\SetupState;
use App\Support\Enums\EnumLabelResolver;
use App\Support\Enums\EnumRegistry;
use App\Support\Schema\SchemaRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Metadata the clients render from (Chapter 11 §11.2, Chapter 12): who am I and what may I do,
 * enum catalogs with configured labels, property schemas (validation, labels, classifications),
 * features and UI-relevant configuration, brand. Nothing here is hard-coded in the frontend.
 */
final class MetaController extends ApiController
{
    /** Non-sensitive configuration exposed to every signed-in client (formatting, behaviour hints). */
    private const CLIENT_CONFIG_PREFIXES = ['locale.', 'ui.', 'pwa.', 'stock.reservation.policy', 'fulfillment.sales.post_on', 'stocktaking.blind_by_default',
        'reporting.max_sync_rows', 'catalog.variants.enabled', 'transfers.batch_verify', 'receiving.'];

    public function __construct(private readonly Access $access, private readonly PlatformConfig $config) {}

    public function me(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $warehouses = DB::table('warehouses as w')->join('branches as b', 'b.id', '=', 'w.branch_id')->where('w.status', '<>', 'closed')
            ->orderBy('w.code')->get(['w.id', 'w.code', 'w.name', 'w.kind', 'w.branch_id', 'b.code as branch_code', 'b.name as branch_name']);
        $scope = $this->access->scopeSummary($actor);
        $visible = $scope['company'] ? $warehouses : $warehouses->filter(fn ($w) => in_array($w->id, $scope['warehouses'], true) || in_array($w->branch_id, $scope['branches'], true));

        return $this->respond([
            'account' => ['id' => $actor->id, 'username' => $actor->username, 'email' => $actor->email, 'display_name' => $actor->displayName(),
                'is_super_admin' => (bool) $actor->is_super_admin, 'mfa_status' => $actor->mfa_status, 'must_change_password' => (bool) $actor->must_change_password,
                'last_login_at' => $actor->last_login_at],
            'permissions' => $this->access->permissionCodes($actor),
            'scope' => $scope,
            'warehouses' => $visible->values()->map(fn ($w) => (array) $w)->all(),
            'features' => $this->config->features(),
            'unread_notifications' => app(NotificationInbox::class)->unreadCount($actor),
        ]);
    }

    public function enums(EnumRegistry $enums): JsonResponse
    {
        return response()->json(['data' => $enums->export()], 200, ['Cache-Control' => 'private, max-age=300']);
    }

    public function schemas(SchemaRegistry $schemas): JsonResponse
    {
        return response()->json(['data' => $schemas->export()], 200, ['Cache-Control' => 'private, max-age=300']);
    }

    public function schema(SchemaRegistry $schemas, string $name): JsonResponse
    {
        $class = $schemas->byName($name) ?? $schemas->byTable($name);
        abort_if($class === null, 404);

        return response()->json(['data' => $class::toArray()]);
    }

    public function permissions(PermissionRegistry $registry): JsonResponse
    {
        return response()->json(['data' => ['modules' => $registry->modules(), 'permissions' => $registry->all()->map(fn ($p) => $p->toArray())->values()->all()]]);
    }

    public function clientConfig(ConfigRegistry $registry): JsonResponse
    {
        $out = [];
        foreach ($registry->all() as $definition) {
            foreach (self::CLIENT_CONFIG_PREFIXES as $prefix) {
                if (str_starts_with($definition->key, $prefix) && ! $definition->sensitive) {
                    $out[$definition->key] = $this->presenter()->data($this->config->get($definition->key));
                }
            }
        }

        return response()->json(['data' => $out]);
    }

    /** Public: brand profile for login/setup screens and the PWA shell. */
    public function brand(BrandResolver $brand, SetupState $setup): JsonResponse
    {
        return response()->json(['data' => $brand->publicProfile() + ['setup_state' => $setup->state()->value]], 200, ['Cache-Control' => 'public, max-age=60']);
    }
}
