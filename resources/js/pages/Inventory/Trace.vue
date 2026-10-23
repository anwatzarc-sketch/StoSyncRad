<script setup lang="ts">
import { onMounted, ref } from 'vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import AreaTabs from '@/components/AreaTabs.vue';
import DataTable from '@/components/ui/DataTable.vue';
import ReferencePicker from '@/components/ui/ReferencePicker.vue';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import { get } from '@/lib/api';
import { enumLabel, loadEnums } from '@/lib/meta';
import { formatDate, formatDateTime, formatQuantity } from '@/lib/format';
import type { Column } from '@/components/ui/table';

/** Company-wide batch / serial traceability (Chapter 07 §7.10): where it came from and where it went. */
const params = new URLSearchParams(window.location.search);
const lotId = ref<string | null>(params.get('lot'));
const serial = ref(params.get('serial') ?? '');
const lotResult = ref<Record<string, any> | null>(null);
const serialResult = ref<Record<string, any> | null>(null);
const error = ref<unknown>(null);

const movementColumns: Column[] = [
    { key: 'posted_at', label: 'When', format: (v) => formatDateTime(v) },
    { key: 'movement_type', label: 'Movement', format: (v) => enumLabel('movement_type', v) },
    { key: 'source_document_number', label: 'Document' },
    { key: 'from_warehouse', label: 'From', format: (v, r) => v ?? enumLabel('inventory_location_type', r.from_type) },
    { key: 'to_warehouse', label: 'To', format: (v, r) => v ?? enumLabel('inventory_location_type', r.to_type) },
    { key: 'partner', label: 'Partner' },
    { key: 'quantity', label: 'Quantity', align: 'right', format: (v) => formatQuantity(v) },
];
const positionColumns: Column[] = [
    { key: 'warehouse', label: 'Warehouse' },
    { key: 'location_type', label: 'Where', format: (v) => enumLabel('inventory_location_type', v) },
    { key: 'stock_condition', label: 'Condition', format: (v) => enumLabel('stock_condition', v) },
    { key: 'quantity_on_hand', label: 'On hand', align: 'right', format: (v) => formatQuantity(v) },
];

async function traceLot() {
    if (!lotId.value) {
        return;
    }
    error.value = null;
    serialResult.value = null;
    try {
        lotResult.value = (await get(`/api/v1/trace/lots/${lotId.value}`)).data;
    } catch (e) {
        error.value = e;
    }
}

async function traceSerial() {
    if (!serial.value.trim()) {
        return;
    }
    error.value = null;
    lotResult.value = null;
    try {
        serialResult.value = (await get(`/api/v1/trace/serials/${encodeURIComponent(serial.value.trim())}`)).data;
    } catch (e) {
        error.value = e;
    }
}

onMounted(async () => {
    await loadEnums().catch(() => undefined);
    if (lotId.value) {
        await traceLot();
    } else if (serial.value) {
        await traceSerial();
    }
});
</script>

<template>
    <AreaTabs area="stock" />
    <PageHeader title="Traceability" subtitle="Follow a batch or serial across every branch" />
    <div class="card mb-4 grid gap-4 md:grid-cols-2">
        <form class="flex items-end gap-2" @submit.prevent="traceLot">
            <div class="flex-1">
                <label for="trace-lot" class="label">Lot / batch</label>
                <ReferencePicker id="trace-lot" v-model="lotId" resource="lots" />
            </div>
            <UiButton type="submit" variant="primary">Trace</UiButton>
        </form>
        <form class="flex items-end gap-2" @submit.prevent="traceSerial">
            <div class="flex-1">
                <label for="trace-serial" class="label">Serial number</label>
                <input id="trace-serial" v-model="serial" class="input font-mono" autocomplete="off">
            </div>
            <UiButton type="submit" variant="primary">Trace</UiButton>
        </form>
    </div>
    <ErrorSummary :error="error" />

    <template v-if="lotResult">
        <section class="card mb-4">
            <h2 class="text-lg font-semibold">{{ lotResult.lot.sku }} · {{ lotResult.lot.item_name }} — lot {{ lotResult.lot.lot_number }}</h2>
            <p class="text-sm text-ink-muted">
                Manufactured {{ formatDate(lotResult.lot.manufacture_date) || '—' }} · Expiry {{ formatDate(lotResult.lot.expiry_date) || '—' }} ·
                QC {{ enumLabel('lot_qc_status', lotResult.lot.qc_status) }} · {{ enumLabel('lot_lock_status', lotResult.lot.lock_status) }}
            </p>
        </section>
        <section class="card mb-4">
            <h2 class="mb-3 font-semibold">Where it is now</h2>
            <DataTable :columns="positionColumns" :rows="lotResult.positions" empty="No stock of this lot remains." caption="Positions" />
        </section>
        <section class="card">
            <h2 class="mb-3 font-semibold">History</h2>
            <DataTable :columns="movementColumns" :rows="lotResult.movements" caption="Movements" />
        </section>
    </template>

    <template v-if="serialResult">
        <section class="card mb-4">
            <h2 class="mb-2 font-semibold">Serial</h2>
            <ul class="text-sm">
                <li v-for="s in serialResult.serials" :key="s.id">{{ s.serial_number }} · {{ s.sku }} {{ s.item_name }} — {{ enumLabel('serial_status', s.lifecycle_status) }}</li>
            </ul>
        </section>
        <section class="card">
            <h2 class="mb-3 font-semibold">History</h2>
            <DataTable :columns="movementColumns" :rows="serialResult.history" caption="History" />
        </section>
    </template>
</template>
