<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import AreaTabs from '@/components/AreaTabs.vue';
import DataTable from '@/components/ui/DataTable.vue';
import EnumSelect from '@/components/ui/EnumSelect.vue';
import ReferencePicker from '@/components/ui/ReferencePicker.vue';
import BarcodeInput from '@/components/BarcodeInput.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import { get } from '@/lib/api';
import { enumLabel, loadEnums } from '@/lib/meta';
import { formatDate, formatQuantity } from '@/lib/format';
import { notify } from '@/lib/toast';
import type { Column } from '@/components/ui/table';
import type { NextAction } from '@/types/shared';

/** Stock search (Chapter 12 §12.6): explicit quantity buckets per position, filtered by scope. */
const itemId = ref<string | null>(new URLSearchParams(window.location.search).get('item_id'));
const warehouseId = ref<string | null>(null);
const condition = ref<string | null>(null);
const code = ref('');
const rows = ref<Record<string, any>[]>([]);
const loading = ref(false);
const error = ref<unknown>(null);
let controller: AbortController | null = null;

const columns: Column[] = [
    { key: 'sku', label: 'SKU' },
    { key: 'item_name', label: 'Item' },
    { key: 'warehouse_code', label: 'Warehouse' },
    { key: 'location_path', label: 'Location' },
    { key: 'lot_number', label: 'Lot' },
    { key: 'expiry_date', label: 'Expiry', format: (v) => formatDate(v) || '—' },
    { key: 'stock_condition', label: 'Condition', format: (v) => enumLabel('stock_condition', v) },
    { key: 'quantity_on_hand', label: 'On hand', align: 'right', format: (v) => formatQuantity(v) },
    { key: 'quantity_reserved', label: 'Reserved', align: 'right', format: (v) => formatQuantity(v) },
    { key: 'quantity_available', label: 'Available', align: 'right', format: (v) => formatQuantity(v) },
];

/** Next action: open the stock card of the item found, else find one by scanning. */
const nextActions = computed<NextAction[]>(() => {
    const first = rows.value[0];
    if (itemId.value && first) {
        return [{ label: `Open ${first.sku} stock card`, icon: 'history', href: `/stock/items/${first.item_id}`, hint: 'Positions, reservations and every movement of this item.' }];
    }

    return [{ label: 'Scan an item or bin', icon: 'scan', onClick: () => document.getElementById('stock-scan')?.focus(),
        hint: 'Scan or pick an item to see where it is and how much is free to use.' }];
});

async function load() {
    controller?.abort();
    controller = new AbortController();
    loading.value = true;
    error.value = null;
    try {
        rows.value = (await get('/api/v1/stock/availability', { item_id: itemId.value, warehouse_id: warehouseId.value, condition: condition.value }, controller.signal)).data;
    } catch (e) {
        if ((e as Error).name !== 'AbortError') {
            error.value = e;
        }
    } finally {
        loading.value = false;
    }
}

async function scan(value: string) {
    try {
        const hit = (await get(`/api/v1/scan/${encodeURIComponent(value)}`)).data;
        if (hit.type === 'item' || hit.type === 'variant') {
            itemId.value = hit.item_id;
        } else if (hit.type === 'location') {
            warehouseId.value = hit.warehouse_id;
        } else if (hit.type === 'lot' && hit.matches?.length) {
            router.visit(`/trace?lot=${hit.matches[0].inventory_lot_id}`);
        } else if (hit.type === 'serial') {
            router.visit(`/trace?serial=${encodeURIComponent(value)}`);
        }
        code.value = '';
    } catch {
        notify(`Nothing matches “${value}”.`, 'error');
    }
}

onMounted(async () => {
    await loadEnums().catch(() => undefined);
    await load();
});
watch([itemId, warehouseId, condition], () => void load());
</script>

<template>
    <AreaTabs area="stock" />
    <PageHeader title="Stock" subtitle="On hand, reserved and available by position" :next="nextActions" />
    <div class="card mb-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
        <div>
            <label for="stock-scan" class="label">Scan</label>
            <BarcodeInput id="stock-scan" v-model="code" placeholder="Barcode, SKU, lot or serial" @scan="scan" />
        </div>
        <div>
            <label for="stock-item" class="label">Item</label>
            <ReferencePicker id="stock-item" v-model="itemId" resource="items" />
        </div>
        <div>
            <label for="stock-warehouse" class="label">Warehouse</label>
            <ReferencePicker id="stock-warehouse" v-model="warehouseId" resource="warehouses" />
        </div>
        <div>
            <label for="stock-condition" class="label">Condition</label>
            <EnumSelect id="stock-condition" v-model="condition" enum-code="stock_condition" nullable placeholder="All conditions" />
        </div>
    </div>
    <ErrorSummary :error="error" />
    <div class="card">
        <DataTable :columns="columns" :rows="rows" :loading="loading" :row-href="(r) => `/stock/items/${r.item_id}`" empty="No stock matches these filters." caption="Stock positions" />
    </div>
</template>
