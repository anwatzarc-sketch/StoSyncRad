<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Actions;

use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Catalog\Enums\CategoryStatus;
use App\Domains\Catalog\Models\ItemCategory;
use App\Domains\Catalog\Schemas\ItemCategorySchema;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Support\Database\AdvisoryLock;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Category tree maintenance (Appendix E): full name/code paths and depth are server-derived and
 * kept consistent for the whole subtree on rename or move; moves are recorded; cycles are refused;
 * archiving requires an empty subtree.
 */
final class SaveCategoryAction
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** @param  array<string, mixed>  $data  validated against ItemCategorySchema */
    public function execute(array $data, ?UserAccount $actor, ?string $id = null): ItemCategory
    {
        return DB::transaction(function () use ($data, $actor, $id) {
            AdvisoryLock::forTransaction(DB::connection(), 'item_category_tree');
            $creating = $id === null;
            $cat = $creating ? new ItemCategory : ItemCategory::query()->lockForUpdate()->findOrFail($id);
            $oldParent = $cat->parent_id;
            $oldName = $cat->name;
            $cat->forceFill(ItemCategorySchema::columns($data));
            $parent = $cat->parent_id ? ItemCategory::query()->findOrFail($cat->parent_id) : null;
            if ($parent !== null && ! $creating && ($parent->id === $cat->id || str_starts_with($parent->code_path.'/', $cat->code_path.'/'))) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'A category cannot be moved under itself or one of its sub-categories.', ['field' => 'parent_id']);
            }
            if ($creating && ItemCategory::query()->where('code', $cat->code)->exists()) {
                throw DomainException::of(ErrorCode::DUPLICATE_RESOURCE, "Category code {$cat->code} is already used.", ['field' => 'code']);
            }
            $cat->forceFill([
                'full_path' => $parent ? $parent->full_path.ItemCategory::PATH_SEPARATOR.$cat->name : $cat->name,
                'code_path' => $parent ? $parent->code_path.'/'.$cat->code : $cat->code,
                'depth' => $parent ? $parent->depth + 1 : 0,
                'status' => $creating ? CategoryStatus::ACTIVE : $cat->status,
                'updated_by' => $actor?->id,
            ] + ($creating ? ['created_by' => $actor?->id] : []))->save();

            if (! $creating && ($oldParent !== $cat->parent_id || $oldName !== $cat->name)) {
                $this->rebuildSubtree($cat);
                if ($oldParent !== $cat->parent_id) {
                    DB::table('item_category_moves')->insert(['id' => (string) Str::uuid7(), 'subject_type' => 'item_category', 'subject_id' => $cat->id,
                        'from_category_id' => $oldParent, 'to_category_id' => $cat->parent_id, 'moved_by' => $actor?->id, 'reason' => $data['move_reason'] ?? null,
                        'created_at' => now()]);
                }
            }
            foreach ((array) ($data['translations'] ?? []) as $locale => $name) {
                DB::table('item_category_translations')->updateOrInsert(['item_category_id' => $cat->id, 'locale' => (string) $locale],
                    ['id' => DB::table('item_category_translations')->where('item_category_id', $cat->id)->where('locale', $locale)->value('id') ?? (string) Str::uuid7(),
                        'name' => (string) $name, 'updated_at' => now(), 'created_at' => now()]);
            }
            $this->audit->record($actor, $creating ? 'catalog.category.created' : 'catalog.category.updated', 'item_categories', $cat->id, null,
                ['full_path' => $cat->full_path]);

            return $cat->refresh();
        });
    }

    public function setStatus(string $id, CategoryStatus $status, ?UserAccount $actor): ItemCategory
    {
        return DB::transaction(function () use ($id, $status, $actor) {
            $cat = ItemCategory::query()->lockForUpdate()->findOrFail($id);
            if ($status !== CategoryStatus::ACTIVE) {
                $subtree = ItemCategory::query()->where('code_path', $cat->code_path)->orWhere('code_path', 'like', str_replace(['%', '_'], ['\\%', '\\_'], $cat->code_path).'/%')->pluck('id');
                $items = DB::table('items')->whereIn('category_id', $subtree)->where('status', '<>', 'archived')->count();
                if ($items > 0) {
                    throw DomainException::of(ErrorCode::POLICY_VIOLATION, "{$cat->full_path} still has $items item(s); move or archive them first.");
                }
                ItemCategory::query()->whereIn('id', $subtree)->update(['status' => $status->value, 'updated_at' => now()]);
            } else {
                $cat->forceFill(['status' => $status])->save();
            }
            $this->audit->record($actor, 'catalog.category.status_changed', 'item_categories', $cat->id, null, ['status' => $status->value]);

            return $cat->refresh();
        });
    }

    private function rebuildSubtree(ItemCategory $root): void
    {
        $queue = [$root];
        while ($queue !== []) {
            $node = array_shift($queue);
            foreach (ItemCategory::query()->where('parent_id', $node->id)->get() as $child) {
                $child->forceFill(['full_path' => $node->full_path.ItemCategory::PATH_SEPARATOR.$child->name, 'code_path' => $node->code_path.'/'.$child->code,
                    'depth' => $node->depth + 1])->save();
                $queue[] = $child;
            }
        }
    }
}
