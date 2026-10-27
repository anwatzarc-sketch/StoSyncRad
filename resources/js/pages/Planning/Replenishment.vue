<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import ReferencePicker from '@/components/ui/ReferencePicker.vue';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import { get, post } from '@/lib/api';
import { formatQuantity } from '@/lib/format';
import { notify } from '@/lib/toast';
import { useShared } from '@/composables/useShared';
import type { NextAction } from '@/types/shared';

/**
 * Replenishment suggestions (Chapter 08 §8.15): projected = available + inbound − committed, with
 * the breakdown visible; converting creates a draft purchase request or transfer for the normal workflow.
 * Next action: submit the drafts just created, else review the first suggestion.
 */
const { can } = useShared();
const warehouseId = ref<string | null>(null);
const rows = ref<Record<string, any>[]>([]);
const quantities = ref<Record<string, string>>({});
const loading = ref(false);
const busy = ref<string | null>(null);
const error = ref<unknown>(null);
const created = ref<Record<string, { type: string; id: string; document_number: string }>>({});

async function load() {
    loading.value = true;
    error.value = null;
    try {
        rows.value = (await get('/api/v1/planning/replenishment', { warehouse_id: warehouseId.value })).data;
        quantities.value = Object.fromEntries(rows.value.map((r) => [r.reorder_rule_id, String(r.proposed_quantity)]));
    } catch (e) {
        error.value = e;
    } finally {
        loading.value = false;
    }
}

async function convert(row: Record<string, any>) {
    busy.value = row.reorder_rule_id;
    error.value = null;
    try {
        const result = (await post(`/api/v1/reorder-rules/${row.reorder_rule_id}/convert`, { quantity: quantities.value[row.reorder_rule_id] })).data;
        created.value[row.reorder_rule_id] = result;
        notify(`Draft ${result.document_number} created.`);
    } catch (e) {
        error.value = e;
    } finally {
        busy.value = null;
    }
}

const docHref = (r: { type: string; id: string }) => `/r/${r.type === 'transfer' || r.type === 'transfer_order' ? 'transfers' : 'purchase-requests'}/${r.id}`;
const nextActions = computed<NextAction[]>(() => {
    const drafts = Object.values(created.value);
    if (drafts.length) {
        return drafts.slice(0, 3).map((d, i) => ({ label: `Submit ${d.document_number}`, icon: 'arrow-right', href: docHref(d),
            hint: i === 0 ? 'Drafts go nowhere until they are submitted for approval.' : undefined }));
    }
    const first = rows.value.find((r) => !created.value[r.reorder_rule_id]);
    if (first && can('planning.replenishment.convert')) {
        return [{ label: `Review ${first.sku}`, icon: 'refresh', onClick: () => document.getElementById(`q-${first.reorder_rule_id}`)?.focus(),
            hint: `${rows.value.length} item(s) at or below their reorder point. Check the quantity, then draft the request.` }];
    }

    return !loading.value && can('catalog.reorder_rule.read')
        ? [{ label: 'Reorder rules', icon: 'sliders', href: '/r/reorder-rules', hint: 'Nothing needs replenishing. Reorder rules decide what appears here.' }] : [];
});

onMounted(load);
watch(warehouseId, () => void load());
</script>

<template>
    <PageHeader title="Replenishment" subtitle="Items at or below their reorder point" :next="nextActions" />
    <div class="card mb-4 max-w-md">
        <label for="rep-warehouse" class="label">Warehouse</label>
        <ReferencePicker id="rep-warehouse" v-model="warehouseId" resource="warehouses" />
    </div>
    <ErrorSummary :error="error" />
    <div class="card">
        <p v-if="!loading && rows.length === 0" class="py-8 text-center text-sm text-ink-muted">Nothing needs replenishing.</p>
        <ul class="divide-y divide-line">
            <li v-for="r in rows" :key="r.reorder_rule_id" class="grid gap-3 py-4 md:grid-cols-[1fr_auto] md:items-center">
                <div class="min-w-0">
                    <p class="font-semibold">{{ r.sku }} · {{ r.item_name }} <span class="text-sm font-normal text-ink-muted">{{ r.warehouse_code }}</span></p>
                    <p class="mt-1 text-sm text-ink-muted tabular-nums">
                        available {{ formatQuantity(r.available) }} + inbound purchases {{ formatQuantity(r.inbound_purchase) }} + inbound transfers
                        {{ formatQuantity(r.inbound_transfer) }} − committed {{ formatQuantity(r.committed_outbound) }} = projected
                        <strong class="text-ink">{{ formatQuantity(r.projected) }}</strong> (reorder point {{ formatQuantity(r.reorder_point) }})
                    </p>
                </div>
                <div v-if="created[r.reorder_rule_id]" class="text-sm">
                    Created <Link :href="docHref(created[r.reorder_rule_id])" class="font-semibold text-brand underline">{{ created[r.reorder_rule_id].document_number }}</Link>
                </div>
                <form v-else-if="can('planning.replenishment.convert')" class="flex items-end gap-2" @submit.prevent="convert(r)">
                    <div>
                        <label :for="`q-${r.reorder_rule_id}`" class="label">Quantity</label>
                        <input :id="`q-${r.reorder_rule_id}`" v-model="quantities[r.reorder_rule_id]" class="input w-32 tabular-nums" inputmode="decimal">
                    </div>
                    <UiButton type="submit" variant="primary" :loading="busy === r.reorder_rule_id">{{ r.method === 'transfer' ? 'Draft transfer' : 'Draft request' }}</UiButton>
                </form>
            </li>
        </ul>
    </div>
</template>
