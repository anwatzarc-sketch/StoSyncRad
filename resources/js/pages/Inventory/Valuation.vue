<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import AreaTabs from '@/components/AreaTabs.vue';
import DataTable from '@/components/ui/DataTable.vue';
import EnumSelect from '@/components/ui/EnumSelect.vue';
import ReferencePicker from '@/components/ui/ReferencePicker.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import UiButton from '@/components/ui/UiButton.vue';
import { get, put } from '@/lib/api';
import { enumLabel, loadEnums } from '@/lib/meta';
import { formatMoney, formatQuantity } from '@/lib/format';
import { notify } from '@/lib/toast';
import { useShared } from '@/composables/useShared';
import type { Column } from '@/components/ui/table';
import type { NextAction } from '@/types/shared';

/**
 * Inventory value by item and warehouse (cost-sensitive: shown only with inventory.valuation.read),
 * and the company costing policy (method and average-cost level), which people holding
 * inventory.valuation.manage_policy change here (step-up; categories/items may override only before
 * their first posting).
 */
const { can } = useShared();
const itemId = ref<string | null>(null);
const rows = ref<Record<string, any>[]>([]);
const total = ref('0');
const loading = ref(false);
const error = ref<unknown>(null);
const POLICY = [
    { key: 'inventory.costing_method', label: 'Costing method', enumCode: 'costing_method' },
    { key: 'inventory.costing_level', label: 'Average cost level', enumCode: 'costing_level' },
] as const;
const policy = ref<Record<string, string | null>>({});
const editing = reactive({ key: null as string | null, value: null as string | null, reason: '', busy: false });
const policyError = ref<unknown>(null);

const columns: Column[] = [
    { key: 'sku', label: 'SKU' },
    { key: 'name', label: 'Item' },
    { key: 'warehouse_code', label: 'Warehouse', format: (v) => v ?? 'Company' },
    { key: 'costing_method', label: 'Costing', format: (v) => enumLabel('costing_method', v) },
    { key: 'quantity', label: 'Quantity', align: 'right', format: (v) => formatQuantity(v) },
    { key: 'average_cost', label: 'Average cost', align: 'right', format: (v) => formatMoney(v) },
    { key: 'total_value', label: 'Value', align: 'right', format: (v) => formatMoney(v) },
];
/** Next action: look at the highest-value position, or the valuation reports. */
const nextActions = computed<NextAction[]>(() => {
    const top = rows.value[0];

    return [
        ...(top ? [{ label: `Open ${top.sku} stock card`, icon: 'history', href: `/stock/items/${top.item_id}`, hint: `Highest value: ${formatMoney(top.total_value)} in ${top.warehouse_code ?? 'the company'}.` }] : []),
        ...(can('reporting.report.run') ? [{ label: 'Valuation reports', icon: 'chart', href: '/reports' }] : []),
    ];
});

async function load() {
    loading.value = true;
    error.value = null;
    try {
        const r = await get('/api/v1/valuation', { item_id: itemId.value });
        rows.value = r.data;
        total.value = r.meta?.total_value ?? '0';
    } catch (e) {
        error.value = e;
    } finally {
        loading.value = false;
    }
}

async function loadPolicy() {
    try {
        const config = (await get<{ data: Record<string, string | null> }>('/api/v1/meta/config')).data;
        policy.value = Object.fromEntries(POLICY.map((p) => [p.key, config[p.key] ?? null]));
    } catch {
        policy.value = {};
    }
}

function edit(key: string) {
    Object.assign(editing, { key, value: policy.value[key] ?? null, reason: '', busy: false });
    policyError.value = null;
}

async function savePolicy() {
    if (!editing.key) {
        return;
    }
    editing.busy = true;
    policyError.value = null;
    try {
        await put(`/api/v1/valuation/policy/${editing.key}`, { value: editing.value, reason: editing.reason || null });
        notify('Costing policy saved.');
        editing.key = null;
        await Promise.all([loadPolicy(), load()]);
    } catch (e) {
        policyError.value = e;
    } finally {
        editing.busy = false;
    }
}

onMounted(async () => {
    await loadEnums().catch(() => undefined);
    await Promise.all([load(), loadPolicy()]);
});
watch(itemId, () => void load());
</script>

<template>
    <AreaTabs area="stock" />
    <PageHeader title="Inventory valuation" :subtitle="`Total ${formatMoney(total)} (top 1,000 positions by value)`" :next="nextActions" />

    <section v-if="Object.keys(policy).length" class="card mb-4" aria-labelledby="policy-title">
        <h2 id="policy-title" class="mb-3 font-semibold">Costing policy</h2>
        <ErrorSummary :error="policyError" />
        <dl class="grid gap-4 sm:grid-cols-2">
            <div v-for="p in POLICY" :key="p.key">
                <dt class="text-xs font-medium uppercase tracking-wide text-ink-muted">{{ p.label }}</dt>
                <dd v-if="editing.key !== p.key" class="mt-1 flex items-center gap-3 text-sm">
                    {{ policy[p.key] ? enumLabel(p.enumCode, policy[p.key]) : '—' }}
                    <UiButton v-if="can('inventory.valuation.manage_policy')" variant="ghost" icon="edit" @click="edit(p.key)">Change</UiButton>
                </dd>
                <dd v-else class="mt-1 space-y-2">
                    <EnumSelect :id="`policy-${p.enumCode}`" v-model="editing.value" :enum-code="p.enumCode" />
                    <label :for="`policy-reason-${p.enumCode}`" class="label">Reason</label>
                    <input :id="`policy-reason-${p.enumCode}`" v-model="editing.reason" class="input" autocomplete="off">
                    <div class="flex gap-2">
                        <UiButton @click="editing.key = null">Cancel</UiButton>
                        <UiButton variant="primary" :loading="editing.busy" @click="savePolicy">Save</UiButton>
                    </div>
                </dd>
            </div>
        </dl>
    </section>

    <div class="card mb-4 max-w-md">
        <label for="valuation-item" class="label">Item</label>
        <ReferencePicker id="valuation-item" v-model="itemId" resource="items" />
    </div>
    <ErrorSummary :error="error" />
    <div class="card">
        <DataTable :columns="columns" :rows="rows" :loading="loading" :row-href="(r) => `/stock/items/${r.item_id}`" caption="Valuation" />
    </div>
</template>
