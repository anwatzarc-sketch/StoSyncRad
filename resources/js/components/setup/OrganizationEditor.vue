<script setup lang="ts">
import EnumSelect from '@/components/ui/EnumSelect.vue';
import UiButton from '@/components/ui/UiButton.vue';
import type { BranchDraft } from '@/types/shared';

/**
 * Branches and their warehouses (Chapter 02 step 7). Each warehouse gets its system locations
 * automatically; an optional pattern such as `Z{1-3}/A{01-10}/B{01-20}` generates storage bins.
 */

const props = defineProps<{ modelValue: BranchDraft[]; existing: Array<Record<string, any>> }>();
const emit = defineEmits<{ 'update:modelValue': [value: BranchDraft[]] }>();

function update(mutator: (rows: BranchDraft[]) => void) {
    const rows = props.modelValue.map((b) => ({ ...b, warehouses: b.warehouses.map((w) => ({ ...w })) }));
    mutator(rows);
    emit('update:modelValue', rows);
}
</script>

<template>
    <div class="space-y-4">
        <div v-if="existing.length" class="rounded-brand bg-surface-muted p-3 text-sm">
            <p class="mb-1 font-semibold">Already created</p>
            <ul class="space-y-1">
                <li v-for="b in existing" :key="b.id">{{ b.code }} · {{ b.name }} — {{ (b.warehouses ?? []).map((w: any) => w.code).join(', ') || 'no warehouses' }}</li>
            </ul>
        </div>
        <fieldset v-for="(b, i) in modelValue" :key="i" class="space-y-3 rounded-brand border border-line p-3">
            <legend class="px-1 text-sm font-semibold">Branch {{ i + 1 }}</legend>
            <div class="grid gap-3 md:grid-cols-3">
                <div>
                    <label :for="`br-code-${i}`" class="label">Code</label>
                    <input :id="`br-code-${i}`" :value="b.code" class="input font-mono uppercase" maxlength="32"
                           @input="update((r) => (r[i].code = ($event.target as HTMLInputElement).value.toUpperCase()))">
                </div>
                <div>
                    <label :for="`br-name-${i}`" class="label">Name</label>
                    <input :id="`br-name-${i}`" :value="b.name" class="input" @input="update((r) => (r[i].name = ($event.target as HTMLInputElement).value))">
                </div>
                <div>
                    <label :for="`br-kind-${i}`" class="label">Kind</label>
                    <EnumSelect :id="`br-kind-${i}`" enum-code="branch_kind" :model-value="b.kind" @update:model-value="update((r) => (r[i].kind = $event as string | null))" />
                </div>
            </div>
            <div v-for="(w, j) in b.warehouses" :key="j" class="grid gap-3 rounded-brand bg-surface-muted p-3 md:grid-cols-[10rem_1fr_1fr_auto] md:items-end">
                <div>
                    <label :for="`wh-code-${i}-${j}`" class="label">Warehouse code</label>
                    <input :id="`wh-code-${i}-${j}`" :value="w.code" class="input font-mono uppercase" maxlength="32"
                           @input="update((r) => (r[i].warehouses[j].code = ($event.target as HTMLInputElement).value.toUpperCase()))">
                </div>
                <div>
                    <label :for="`wh-name-${i}-${j}`" class="label">Name</label>
                    <input :id="`wh-name-${i}-${j}`" :value="w.name" class="input" @input="update((r) => (r[i].warehouses[j].name = ($event.target as HTMLInputElement).value))">
                </div>
                <div>
                    <label :for="`wh-pattern-${i}-${j}`" class="label">Bin pattern (optional)</label>
                    <input :id="`wh-pattern-${i}-${j}`" :value="w.location_pattern" class="input font-mono" placeholder="Z{1-2}/A{01-05}/B{01-10}"
                           @input="update((r) => (r[i].warehouses[j].location_pattern = ($event.target as HTMLInputElement).value))">
                </div>
                <button type="button" class="btn btn-ghost text-danger" @click="update((r) => r[i].warehouses.splice(j, 1))">Remove</button>
            </div>
            <div class="flex flex-wrap gap-2">
                <UiButton @click="update((r) => r[i].warehouses.push({ code: '', name: '', location_pattern: '' }))">+ Warehouse</UiButton>
                <UiButton variant="ghost" class="text-danger" @click="update((r) => r.splice(i, 1))">Remove branch</UiButton>
            </div>
        </fieldset>
        <UiButton @click="update((r) => r.push({ code: '', name: '', kind: r.length === 0 && existing.length === 0 ? 'headquarters' : null, warehouses: [{ code: '', name: '', location_pattern: '' }] }))">
            + Branch
        </UiButton>
    </div>
</template>
