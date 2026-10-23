<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use App\Domains\Fulfillment\Models\StockRequest;
use App\Domains\Fulfillment\Services\StockRequestWorkflow;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Organization\Enums\BranchKind;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Platform\Enums\PlatformSetupState;
use App\Domains\Platform\Seeds\ApprovalPoliciesSeed;
use App\Domains\Platform\Seeds\DocumentSequencesSeed;
use App\Domains\Platform\Seeds\SeedRunner;
use App\Domains\Platform\Seeds\SodRulesSeed;
use App\Domains\Platform\Services\SetupState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BuildsActors;
use Tests\Support\BuildsInventory;
use Tests\TestCase;

/** People who only request stock (the Stock requester role template) work with their own requests and nothing else. */
final class StockRequesterTest extends TestCase
{
    use BuildsActors;
    use BuildsInventory;

    /** The Stock requester role template (RoleTemplatesSeed). */
    private const REQUESTER = ['fulfillment.stock_request.read_own', 'fulfillment.stock_request.create_own', 'fulfillment.stock_request.cancel_own',
        'catalog.item.read', 'inventory.stock.read'];

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([DocumentSequencesSeed::class, SodRulesSeed::class, ApprovalPoliciesSeed::class] as $seed) {
            app(SeedRunner::class)->run($seed);
        }
        DB::table('platform_setup')->delete();
        DB::table('platform_setup')->insert(['id' => (string) Str::uuid7(), 'deployment_id' => (string) Str::uuid7(), 'state' => PlatformSetupState::COMPLETED->value,
            'completed_steps' => '[]', 'completed_at' => now(), 'locked_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        app(SetupState::class)->refresh();
    }

    public function test_a_requester_lists_opens_submits_and_cancels_only_their_own_requests(): void
    {
        $wh = $this->site();
        $item = $this->item();
        $requester = $this->actorWith(self::REQUESTER);
        $someoneElses = $this->draftRequest($wh, $this->superAdmin());

        $created = $this->as($requester)->postJson('/api/v1/stock-requests/mine', ['warehouse_id' => $wh->id, 'purpose' => 'Ward 3 restock',
            'lines' => [['item_id' => $item->id, 'quantity' => '4']]])->assertCreated()->json('data');

        $this->assertSame([$created['id']], array_column($this->as($requester)->getJson('/api/v1/stock-requests/mine')->assertOk()->json('data'), 'id'));
        $this->as($requester)->getJson("/api/v1/stock-requests/mine/{$created['id']}")->assertOk()->assertJsonPath('data.status', 'draft');
        $this->as($requester)->getJson("/api/v1/stock-requests/mine/$someoneElses->id")->assertNotFound();

        $this->as($requester)->postJson("/api/v1/stock-requests/mine/{$created['id']}/submit", ['version' => $created['version']])
            ->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->as($requester)->postJson("/api/v1/stock-requests/mine/$someoneElses->id/submit", [])->assertForbidden();
        $this->as($requester)->postJson("/api/v1/stock-requests/mine/{$created['id']}/cancel", ['reason' => 'No longer needed'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->as($requester)->getJson('/api/v1/stock-requests')->assertForbidden();
    }

    public function test_the_request_pages_use_the_own_record_endpoints_for_requesters(): void
    {
        $this->withoutVite();
        $requester = $this->actorWith(self::REQUESTER);

        $this->actingAs($requester, 'web')->get('/r/stock-requests')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('resource.api', '/api/v1/stock-requests/mine')
            ->where('resource.api_create', '/api/v1/stock-requests/mine')
            ->where('resource.can_create', true));
        $actions = collect($this->actingAs($requester, 'web')->get('/r/stock-requests/'.Str::uuid7())->viewData('page')['props']['resource']['actions'])->keyBy('name');

        $this->assertSame(['cancel', 'submit'], $actions->keys()->sort()->values()->all());
        $this->assertSame('/api/v1/stock-requests/mine/{id}/submit', $actions['submit']['uri']);
    }

    private function site(): Warehouse
    {
        return $this->warehouse('W'.Str::upper(Str::random(5)), $this->branch('B'.Str::upper(Str::random(4)), BranchKind::HEADQUARTERS));
    }

    private function draftRequest(Warehouse $wh, UserAccount $by): StockRequest
    {
        return app(StockRequestWorkflow::class)->save(['warehouse_id' => $wh->id, 'purpose' => 'Store restock',
            'lines' => [['item_id' => $this->item()->id, 'quantity' => '2']]], $by);
    }

    private function as(UserAccount $account): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($account->createToken('t')->plainTextToken);
    }
}
