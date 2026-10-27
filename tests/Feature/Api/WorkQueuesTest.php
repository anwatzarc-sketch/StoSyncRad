<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domains\IdentityAccess\Actions\AssignRoleAction;
use App\Domains\IdentityAccess\Actions\SaveRoleAction;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Services\Access;
use App\Domains\IdentityAccess\Services\StepUpVerifier;
use App\Domains\Inventory\Services\ReversalService;
use App\Domains\Organization\Enums\BranchKind;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Platform\Enums\PlatformSetupState;
use App\Domains\Platform\Seeds\ApprovalPoliciesSeed;
use App\Domains\Platform\Seeds\DocumentSequencesSeed;
use App\Domains\Platform\Seeds\SeedRunner;
use App\Domains\Platform\Seeds\SodRulesSeed;
use App\Domains\Platform\Services\SetupState;
use App\Domains\Stocktaking\Models\StockAdjustment;
use App\Domains\Stocktaking\Services\StockAdjustmentWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsActors;
use Tests\Support\BuildsInventory;
use Tests\TestCase;

/** Chapter 12 §12.6 — "My work" counts what a person can act on, where they can act on it. */
final class WorkQueuesTest extends TestCase
{
    use BuildsActors;
    use BuildsInventory;

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

    public function test_approval_queues_count_where_the_person_may_approve_not_where_they_may_read(): void
    {
        $here = $this->site();
        $elsewhere = $this->site();
        $clerk = $this->superAdmin();
        $mine = $this->submittedAdjustment($here, $clerk);
        $this->submittedAdjustment($elsewhere, $clerk);
        $approver = $this->actorWith(['stocktaking.adjustment.read']);
        $this->grant($approver, ['stocktaking.adjustment.approve'], 'warehouse', $here->id);

        $waiting = collect($this->as($approver)->getJson('/api/v1/work')->assertOk()->json('data.waiting'))->keyBy('key');
        $this->assertSame(1, $waiting['stock-adjustments.approve']['count']);
        $this->assertSame("/r/stock-adjustments/$mine->id", $waiting['stock-adjustments.approve']['href'], 'a single document opens directly');
        $rows = $this->as($approver)->getJson('/api/v1/work/queues/stock-adjustments.approve')->assertOk()->json('data');
        $this->assertSame([$mine->id], array_column($rows, 'id'));

        $reader = $this->actorWith(['stocktaking.adjustment.read']);
        $this->assertArrayNotHasKey('stock-adjustments.approve', collect($this->as($reader)->getJson('/api/v1/work')->json('data.waiting'))->keyBy('key')->all());
        $this->as($reader)->getJson('/api/v1/work/queues/stock-adjustments.approve')->assertForbidden();
    }

    public function test_approval_queues_leave_out_documents_a_separation_of_duties_rule_forbids_the_person_to_approve(): void
    {
        $wh = $this->site();
        $approver = $this->actorWith(['stocktaking.adjustment.read', 'stocktaking.adjustment.create', 'stocktaking.adjustment.submit', 'stocktaking.adjustment.approve']);
        $this->submittedAdjustment($wh, $approver);
        $someoneElses = $this->submittedAdjustment($wh, $this->superAdmin());

        $rows = $this->as($approver)->getJson('/api/v1/work/queues/stock-adjustments.approve')->assertOk()->json('data');

        $this->assertSame([$someoneElses->id], array_column($rows, 'id'), 'the creator may not approve their own adjustment (adjustment_creator_not_approver)');
    }

    public function test_requested_stock_reversals_wait_for_a_second_person_who_posts_them_from_the_queue(): void
    {
        $wh = $this->site();
        $item = $this->item();
        $movementId = $this->receive($item, $wh, '10', '4')->body['movement_id'];
        $reversal = app(ReversalService::class)->request($movementId, $this->superAdmin(), ['reason' => 'Received twice']);
        $approver = $this->actorWith(['inventory.movement.read']);
        $this->grant($approver, ['inventory.movement.reverse'], 'warehouse', $wh->id);

        $waiting = collect($this->as($approver)->getJson('/api/v1/work')->assertOk()->json('data.waiting'))->keyBy('key');
        $this->assertSame(1, $waiting['movement-reversals.approve']['count']);
        $this->assertSame("/r/movement-reversals/$reversal->id", $waiting['movement-reversals.approve']['href']);
        $this->assertSame([$reversal->id], array_column($this->as($approver)->getJson('/api/v1/movement-reversals')->assertOk()->json('data'), 'id'));

        $this->app['auth']->forgetGuards();
        $this->actingAs($approver, 'web')->withHeader('Referer', config('app.url'))->withSession([StepUpVerifier::SESSION_KEY => time()])
            ->withHeader('Idempotency-Key', (string) Str::uuid7())->postJson("/api/v1/reversals/$reversal->id/approve", [])->assertCreated();

        $this->assertSame('posted', DB::table('stock_movement_reversals')->where('id', $reversal->id)->value('status'));
        $this->assertSame('0.000000', $this->onHand($item, $wh));
        $this->assertArrayNotHasKey('movement-reversals.approve', collect($this->as($approver)->getJson('/api/v1/work')->json('data.waiting'))->keyBy('key')->all());
    }

    public function test_unknown_hubs_and_queues_are_not_found(): void
    {
        $person = $this->actorWith(['stocktaking.adjustment.read']);

        $this->as($person)->getJson('/api/v1/work?hub=warp-drive')->assertNotFound();
        $this->as($person)->getJson('/api/v1/work/queues/stock-adjustments.teleport')->assertNotFound();
    }

    private function site(): Warehouse
    {
        return $this->warehouse('W'.Str::upper(Str::random(5)), $this->branch('B'.Str::upper(Str::random(4)), BranchKind::HEADQUARTERS));
    }

    private function submittedAdjustment(Warehouse $wh, UserAccount $by): StockAdjustment
    {
        $flow = app(StockAdjustmentWorkflow::class);
        $adjustment = $flow->save(['warehouse_id' => $wh->id, 'adjustment_type' => 'found', 'reason' => 'Found in aisle 4', 'lines' => [
            ['item_id' => $this->item()->id, 'storage_location_id' => $wh->default_storage_location_id, 'quantity' => '2', 'unit_cost' => '5'],
        ]], $by);

        return $flow->submit($adjustment->id, $by);
    }

    /** @param  array<int, string>  $permissions */
    private function grant(UserAccount $account, array $permissions, string $scopeType, ?string $scopeId): void
    {
        $role = app(SaveRoleAction::class)->execute(['code' => 'r_'.Str::lower(Str::random(8)), 'name' => 'Extra role', 'permissions' => $permissions], null);
        app(AssignRoleAction::class)->execute(['user_account_id' => $account->id, 'role_id' => $role->id, 'scope_type' => $scopeType, 'scope_id' => $scopeId], null);
        app(Access::class)->forget();
    }

    private function as(UserAccount $account): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($account->createToken('t')->plainTextToken);
    }
}
