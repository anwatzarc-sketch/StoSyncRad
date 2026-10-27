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
import type { NextAction } from '@/types/shared';

/**
 * Item stock view: positions by warehouse/condition, the stock card (running balance), its barcodes,
 * where to pick a quantity from (the removal strategy's suggestion) and its demand. Next action: use
 * the stock (issue, reserve, transfer), or reorder when there is none.
 */
const props = defineProps<{ itemId: string }>();
const { can } = useShared();

const item = ref<Record<string, any> | null>(null);
const positions = ref<Record<string, any>[]>([]);
const warehouseId = ref<string | null>(null);
const from = ref(new Date(Date.now() - 30 * 86400000).toISOString().slice(0, 10));
const to = ref(new Date().toISOString().slice(0, 10));
const card = ref<{ opening_balance: string; closing_balance: string; lines: Record<string, any>[] } | null>(null);
const error = ref<unknown>(null);
const barcodes = ref<Record<string, any>[]>([]);
const pickQuantity = ref('1');
const pickPlan = ref<Record<string, any>[] | null>(null);
const pickError = ref<unknown>(null);
const demand = ref<{ history: Array<{ period: string; gross: string; returns: string; net: string }>; forecast: { daily: string; method: string } } | null>(null);

const warehouses = computed(() => Array.from(new Map(positions.value.filter((p) => p.warehouse_id).map((p) => [p.warehouse_id, p.warehouse_code])).entries()));
const positionColumns: Column[] = [
    { key: 'warehouse_code', label: 'Warehouse' },
    { key: 'location_type', label: 'Where', format: (v) => (v === 'in_transit' ? 'In transit' : 'In stock') },
    { key: 'stock_condition', label: 'Condition', format: (v) => enumLabel('stock_condition', v) },
    { key: 'on_hand', label: 'On hand', align: 'right', format: (v) => formatQuantity(v) },
    { key: 'reserved', label: 'Reserved', align: 'right', format: (v) => formatQuantity(v) },
    { key: 'available', label: 'Available', align: 'right', format: (v) => formatQuantity(v) },
];
const barcodeColumns: Column[] = [
    { key: 'barcode', label: 'Barcode' },
    { key: 'symbology', label: 'Type', format: (v) => enumLabel('barcode_symbology', v) },
    { key: 'is_primary', label: 'Primary', format: (v) => (v ? 'Yes' : '') },
    { key: 'item_packaging_id', label: 'Packaging', format: (v) => (v ? 'Pack' : 'Each') },
];
const pickColumns: Column[] = [
    { key: 'location_path', label: 'Location' },
    { key: 'lot_number', label: 'Lot', format: (v) => (v ? String(v) : '—') },
    { key: 'expiry_date', label: 'Expiry', format: (v) => formatDate(v) || '—' },
    { key: 'available', label: 'Available', align: 'right', format: (v) => formatQuantity(v) },
    { key: 'take', label: 'Take', align: 'right', format: (v) => formatQuantity(v) },
];
const demandColumns: Column[] = [
    { key: 'period', label: 'Month', format: (v) => formatDate(v) },
    { key: 'gross', label: 'Used / sold', align: 'right', format: (v) => formatQuantity(v) },
    { key: 'returns', label: 'Returned', align: 'right', format: (v) => formatQuantity(v) },
    { key: 'net', label: 'Net demand', align: 'right', format: (v) => formatQuantity(v) },
];
const available = computed(() => positions.value.reduce((n, p) => n + Number(p.available ?? 0), 0));
const nextActions = computed<NextAction[]>(() => {
    if (!item.value) {
        return [];
    }
    if (available.value <= 0) {
        return [
            ...(can('planning.replenishment.read') ? [{ label: 'See what to reorder', icon: 'refresh', href: '/planning/replenishment', hint: 'Nothing of this item is free to use in your warehouses.' }] : []),
            ...(can('procurement.purchase_request.create') ? [{ label: 'Request a purchase', icon: 'clipboard', href: '/r/purchase-requests/new' }] : []),
        ];
    }
    const at = warehouseId.value ? `&prefill[warehouse_id]=${warehouseId.value}` : '';
    const actions: NextAction[] = [
        ...(can('fulfillment.stock_issue.create') ? [{ label: 'Issue stock', icon: 'package', href: '/r/stock-issues/new' }] : []),
        ...(can('inventory.reservation.create') ? [{ label: 'Reserve stock', icon: 'lock', href: `/r/reservations/new?prefill[item_id]=${props.itemId}${at}` }] : []),
        ...(can('transfers.transfer.create') ? [{ label: 'Transfer stock', icon: 'truck', href: '/r/transfers/new' }] : []),
    ];

    return actions.map((a, i) => (i === 0 ? { ...a, hint: `${formatQuantity(String(available.value))} free to use across your warehouses.` } : a));
});

async function planPick() {
    pickError.value = null;
    try {
        pickPlan.value = (await get('/api/v1/stock/pick-suggestions', { item_id: props.itemId, warehouse_id: warehouseId.value, quantity: pickQuantity.value })).data;
    } catch (e) {
        pickError.value = e;
        pickPlan.value = null;
    }
}

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
    await Promise.all([
        can('catalog.barcode.read') ? get(`/api/v1/items/${props.itemId}/barcodes`).then((r) => (barcodes.value = r.data)).catch(() => undefined) : null,
        can('planning.demand.read') ? get('/api/v1/planning/demand', { item_id: props.itemId }).then((r) => (demand.value = r.data)).catch(() => undefined) : null,
    ]);
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
watch(warehouseId, () => (pickPlan.value = null));
</script>

<template>
    <PageHeader :title="item ? `${item.sku} · ${item.name}` : 'Item stock'" back="/stock" :next="nextActions">
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

    <section v-if="warehouses.length" class="card mb-6">
        <div class="mb-3 flex flex-wrap items-end gap-3">
            <h2 class="mr-auto text-lg font-semibold">Where to pick</h2>
            <div>
                <label for="pick-warehouse" class="label">Warehouse</label>
                <select id="pick-warehouse" v-model="warehouseId" class="input">
                    <option v-for="[id, code] in warehouses" :key="id" :value="id">{{ code }}</option>
                </select>
            </div>
            <div>
                <label for="pick-quantity" class="label">Quantity</label>
                <input id="pick-quantity" v-model="pickQuantity" class="input w-32 text-right tabular-nums" inputmode="decimal">
            </div>
            <UiButton icon="route" @click="planPick">Suggest bins</UiButton>
        </div>
        <ErrorSummary :error="pickError" />
        <DataTable v-if="pickPlan" :columns="pickColumns" :rows="pickPlan" empty="Not enough available stock in this warehouse." caption="Pick suggestion" />
        <p v-else class="text-sm text-ink-muted">Bins and lots to take from, in the order the item's removal strategy (FEFO / FIFO) wants them used.</p>
    </section>

    <section v-if="barcodes.length" class="card mb-6">
        <h2 class="mb-3 text-lg font-semibold">Barcodes</h2>
        <DataTable :columns="barcodeColumns" :rows="barcodes" caption="Barcodes" />
    </section>

    <section v-if="demand" class="card mb-6">
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-lg font-semibold">Demand</h2>
            <p class="text-sm text-ink-muted">About <strong class="tabular-nums text-ink">{{ formatQuantity(demand.forecast.daily) }}</strong> a day
                ({{ enumLabel('forecast_method', demand.forecast.method) }})</p>
        </div>
        <DataTable :columns="demandColumns" :rows="demand.history" empty="No demand in the last twelve months." caption="Demand by month" />
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
