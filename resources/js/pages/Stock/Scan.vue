<script setup lang="ts">
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import BarcodeInput from '@/components/BarcodeInput.vue';
import { ApiError, get } from '@/lib/api';
import { formatDate } from '@/lib/format';

/** Scan anything (item, variant, location, lot, serial) and jump to it. Scanning never posts. */
const code = ref('');
const hit = ref<Record<string, any> | null>(null);
const notFound = ref<string | null>(null);
const busy = ref(false);
const scanned = ref('');

async function scan(value: string) {
    busy.value = true;
    notFound.value = null;
    hit.value = null;
    scanned.value = value;
    try {
        hit.value = (await get(`/api/v1/scan/${encodeURIComponent(value)}`)).data;
    } catch (e) {
        notFound.value = e instanceof ApiError && e.status === 404 ? `Nothing matches “${value}”.` : (e as Error).message;
    } finally {
        busy.value = false;
        code.value = '';
    }
}
</script>

<template>
    <PageHeader title="Scan" subtitle="Item, location, lot or serial" />
    <div class="card mx-auto max-w-xl space-y-4">
        <label for="scan-code" class="label">Code</label>
        <BarcodeInput id="scan-code" v-model="code" autofocus @scan="scan" />
        <p v-if="busy" class="text-sm text-ink-muted">Looking up…</p>
        <p v-if="notFound" class="error-text" role="alert">{{ notFound }}</p>
        <div v-if="hit" class="rounded-brand bg-surface-muted p-4" role="status">
            <template v-if="hit.type === 'item' || hit.type === 'variant'">
                <p class="text-sm text-ink-muted">{{ hit.type === 'variant' ? 'Variant' : 'Item' }}</p>
                <p class="text-lg font-semibold">{{ hit.sku }} · {{ hit.name }}</p>
                <Link :href="`/stock/items/${hit.item_id ?? hit.id}`" class="btn btn-primary mt-3">Show stock</Link>
            </template>
            <template v-else-if="hit.type === 'location'">
                <p class="text-sm text-ink-muted">Location</p>
                <p class="text-lg font-semibold">{{ hit.full_path }}</p>
                <Link :href="`/r/storage-locations/${hit.storage_location_id}`" class="btn btn-primary mt-3">Open location</Link>
            </template>
            <template v-else-if="hit.type === 'serial'">
                <p class="text-sm text-ink-muted">Serial</p>
                <p class="text-lg font-semibold">{{ scanned }} · {{ hit.sku }} {{ hit.name }}</p>
                <Link :href="`/trace?serial=${encodeURIComponent(scanned)}`" class="btn btn-primary mt-3">Trace serial</Link>
            </template>
            <template v-else-if="hit.type === 'lot'">
                <p class="text-sm text-ink-muted">Lot</p>
                <ul class="divide-y divide-line">
                    <li v-for="l in hit.matches" :key="l.inventory_lot_id" class="flex items-center justify-between gap-3 py-2">
                        <span>{{ l.lot_number }} · {{ l.sku }} <span class="text-sm text-ink-muted">{{ l.expiry_date ? `exp. ${formatDate(l.expiry_date)}` : '' }}</span></span>
                        <Link :href="`/trace?lot=${l.inventory_lot_id}`" class="btn btn-secondary min-h-9">Trace</Link>
                    </li>
                </ul>
            </template>
        </div>
    </div>
</template>
