<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import DataTable from '@/components/ui/DataTable.vue';
import ReferencePicker from '@/components/ui/ReferencePicker.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import { get } from '@/lib/api';
import { enumLabel, loadEnums } from '@/lib/meta';
import { formatMoney, formatQuantity } from '@/lib/format';
import type { Column } from '@/components/ui/table';

/** Inventory value by item and warehouse (cost-sensitive: shown only with inventory.valuation.read). */
const itemId = ref<string | null>(null);
const rows = ref<Record<string, any>[]>([]);
const total = ref('0');
const loading = ref(false);
const error = ref<unknown>(null);

const columns: Column[] = [
    { key: 'sku', label: 'SKU' },
    { key: 'name', label: 'Item' },
    { key: 'warehouse_code', label: 'Warehouse', format: (v) => v ?? 'Company' },
    { key: 'costing_method', label: 'Costing', format: (v) => enumLabel('costing_method', v) },
    { key: 'quantity', label: 'Quantity', align: 'right', format: (v) => formatQuantity(v) },
    { key: 'average_cost', label: 'Average cost', align: 'right', format: (v) => formatMoney(v) },
    { key: 'total_value', label: 'Value', align: 'right', format: (v) => formatMoney(v) },
];

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

onMounted(async () => {
    await loadEnums().catch(() => undefined);
    await load();
});
watch(itemId, () => void load());
</script>

<template>
    <PageHeader title="Inventory valuation" :subtitle="`Total ${formatMoney(total)} (top 1,000 positions by value)`" />
    <div class="card mb-4 max-w-md">
        <label for="valuation-item" class="label">Item</label>
        <ReferencePicker id="valuation-item" v-model="itemId" resource="items" />
    </div>
    <ErrorSummary :error="error" />
    <div class="card">
        <DataTable :columns="columns" :rows="rows" :loading="loading" caption="Valuation" />
    </div>
</template>
