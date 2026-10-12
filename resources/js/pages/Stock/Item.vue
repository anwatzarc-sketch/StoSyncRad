<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import DataTable from '@/components/ui/DataTable.vue';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import { download, get } from '@/lib/api';
import { enumLabel, loadEnums } from '@/lib/meta';
import { formatDate, formatDateTime, formatQuantity } from '@/lib/format';
import { useShared } from '@/composables/useShared';
import type { Column } from '@/components/ui/table';

/** Item stock view: positions by warehouse/condition and the stock card (running balance). */
const props = defineProps<{ itemId: string }>();
const { can } = useShared();

const item = ref<Record<string, any> | null>(null);
const positions = ref<Record<string, any>[]>([]);
const warehouseId = ref<string | null>(null);
const from = ref(new Date(Date.now() - 30 * 86400000).toISOString().slice(0, 10));
const to = ref(new Date().toISOString().slice(0, 10));
const card = ref<{ opening_balance: string; closing_balance: string; lines: Record<string, any>[] } | null>(null);
const error = ref<unknown>(null);

const warehouses = computed(() => Array.from(new Map(positions.value.filter((p) => p.warehouse_id).map((p) => [p.warehouse_id, p.warehouse_code])).entries()));
const positionColumns: Column[] = [
    { key: 'warehouse_code', label: 'Warehouse' },
    { key: 'location_type', label: 'Where', format: (v) => (v === 'in_transit' ? 'In transit' : 'In stock') },
    { key: 'stock_condition', label: 'Condition', format: (v) => enumLabel('stock_condition', v) },
    { key: 'on_hand', label: 'On hand', align: 'right', format: (v) => formatQuantity(v) },
    { key: 'reserved', label: 'Reserved', align: 'right', format: (v) => formatQuantity(v) },
    { key: 'available', label: 'Available', align: 'right', format: (v) => formatQuantity(v) },
];
const cardColumns: Column[] = [
    { key: 'business_date', label: 'Date', format: (v) => formatDate(v) },
    { key: 'movement_number', label: 'Movement' },
    { key: 'movement_type', label: 'Type', format: (v) => enumLabel('movement_type', v) },
    { key: 'source_document_number', label: 'Document' },
    { key: 'lot_number', label: 'Lot' },
    { key: 'change', label: 'Change', align: 'right', format: (v) => formatQuantity(v) },
    { key: 'balance', label: 'Balance', align: 'right', format: (v) => formatQuantity(v) },
    { key: 'posted_at', label: 'Posted', format: (v) => formatDateTime(v) },
];

onMounted(async () => {
    await loadEnums().catch(() => undefined);
    try {
        const r = (await get(`/api/v1/stock/items/${props.itemId}`)).data;
        item.value = r.item;
        positions.value = r.positions;
        warehouseId.value = warehouses.value[0]?.[0] ?? null;
    } catch (e) {
        error.value = e;
    }
});

async function loadCard() {
    if (!warehouseId.value || !can('inventory.movement.read')) {
        card.value = null;

        return;
    }
    try {
        card.value = (await get(`/api/v1/stock/items/${props.itemId}/card`, { warehouse_id: warehouseId.value, from: from.value, to: to.value })).data;
    } catch (e) {
        error.value = e;
    }
}

watch([warehouseId, from, to], () => void loadCard());
</script>

<template>
    <PageHeader :title="item ? `${item.sku} · ${item.name}` : 'Item stock'" back="/stock">
        <template #actions>
            <UiButton v-if="item" icon="tag" :href="`/r/items/${itemId}`">Item record</UiButton>
            <UiButton v-if="item && can('catalog.label.print')" icon="printer" @click="download(`/api/v1/items/${itemId}/label`, `${item.sku}-label.pdf`)">Label</UiButton>
        </template>
    </PageHeader>
    <ErrorSummary :error="error" />

    <section class="card mb-6">
        <h2 class="mb-3 text-lg font-semibold">Positions</h2>
        <DataTable :columns="positionColumns" :rows="positions" empty="No stock for this item in your warehouses." caption="Positions" />
    </section>

    <section v-if="can('inventory.movement.read')" class="card">
        <div class="mb-3 flex flex-wrap items-end gap-3">
            <h2 class="mr-auto text-lg font-semibold">Stock card</h2>
            <div>
                <label for="card-warehouse" class="label">Warehouse</label>
                <select id="card-warehouse" v-model="warehouseId" class="input">
                    <option v-for="[id, code] in warehouses" :key="id" :value="id">{{ code }}</option>
                </select>
            </div>
            <div>
                <label for="card-from" class="label">From</label>
                <input id="card-from" v-model="from" type="date" class="input">
            </div>
            <div>
                <label for="card-to" class="label">To</label>
                <input id="card-to" v-model="to" type="date" class="input">
            </div>
        </div>
        <template v-if="card">
            <p class="mb-2 text-sm">Opening balance <strong class="tabular-nums">{{ formatQuantity(card.opening_balance) }}</strong> · Closing balance
                <strong class="tabular-nums">{{ formatQuantity(card.closing_balance) }}</strong></p>
            <DataTable :columns="cardColumns" :rows="card.lines" empty="No movements in this period." caption="Stock card" />
        </template>
    </section>
</template>
