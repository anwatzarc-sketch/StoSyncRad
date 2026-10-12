<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import ReferencePicker from '@/components/ui/ReferencePicker.vue';
import BarcodeInput from '@/components/BarcodeInput.vue';
import { get } from '@/lib/api';
import { formatDate, formatQuantity } from '@/lib/format';
import { notify } from '@/lib/toast';
import { usePosting } from '@/composables/usePosting';

/**
 * Transfer dispatch (Chapter 08 §8.5, diagram "inter-branch transfer dispatch"): pick from the
 * suggested positions (FEFO/FIFO per category), confirm by scanning location or lot, enter serials
 * for serial-tracked items, then post — batch ids are locked in transit by the server.
 */
interface Pick { storage_location_id: string; location_path: string; lot_id: string | null; lot_number: string | null; expiry_date: string | null; available: string; quantity: string; serials: string; reserved: boolean; confirmed: boolean }
interface Line { id: string; sku: string; name: string; tracking_mode: string; outstanding: string; picks: Pick[] }

const props = defineProps<{ transferId: string }>();
const header = ref<Record<string, any> | null>(null);
const lines = ref<Line[]>([]);
const shipping = reactive({ carrier_partner_id: null as string | null, tracking_reference: '', seal_reference: '', vehicle_reference: '', expected_arrival: '' });
const code = ref('');
const loadError = ref<unknown>(null);
const { busy, error, post } = usePosting(`dispatch:${props.transferId}`);

const isSerial = (l: Line) => l.tracking_mode.includes('serial');
const serialsOf = (p: Pick) => p.serials.split(/\s+/).map((x) => x.trim()).filter(Boolean);
const total = computed(() => lines.value.reduce((n, l) => n + l.picks.filter((p) => (isSerial(l) ? serialsOf(p).length > 0 : Number(p.quantity) > 0)).length, 0));

onMounted(async () => {
    try {
        const sheet = (await get(`/api/v1/transfers/${props.transferId}/dispatch-sheet`)).data;
        header.value = sheet.transfer;
        lines.value = sheet.lines.filter((l: any) => Number(l.outstanding) > 0).map((l: any) => ({
            id: l.id, sku: l.sku, name: l.name, tracking_mode: l.tracking_mode, outstanding: l.outstanding,
            picks: (l.suggestions ?? []).map((s: any) => ({ storage_location_id: s.storage_location_id, location_path: s.location_path, lot_id: s.lot_id, lot_number: s.lot_number,
                expiry_date: s.expiry_date, available: s.available, quantity: String(s.take), serials: '', reserved: !!s.reserved, confirmed: false })),
        }));
    } catch (e) {
        loadError.value = e;
    }
});

function scan(value: string) {
    const v = value.trim().toLowerCase();
    let hits = 0;
    for (const l of lines.value) {
        for (const p of l.picks) {
            if (p.location_path.toLowerCase() === v || p.location_path.toLowerCase().endsWith(`/${v}`) || (p.lot_number ?? '').toLowerCase() === v) {
                p.confirmed = true;
                hits++;
            }
        }
    }
    notify(hits ? `Confirmed ${hits} pick(s).` : `“${value}” is not on this pick list.`, hits ? 'success' : 'error');
    code.value = '';
}

async function dispatch() {
    const payload = lines.value.flatMap((l) => l.picks
        .filter((p) => (isSerial(l) ? serialsOf(p).length > 0 : Number(p.quantity) > 0))
        .map((p) => ({ transfer_order_line_id: l.id, storage_location_id: p.storage_location_id, inventory_lot_id: p.lot_id,
            ...(isSerial(l) ? { quantity: String(serialsOf(p).length), serial_numbers: serialsOf(p) } : { quantity: p.quantity }) })));
    const result = await post(`/api/v1/transfers/${props.transferId}/dispatch`, {
        lines: payload, version: header.value?.version,
        ...Object.fromEntries(Object.entries(shipping).filter(([, v]) => v)),
    });
    if (result) {
        notify('Dispatched — the stock is now in transit.');
        router.visit(`/r/transfers/${props.transferId}`);
    }
}
</script>

<template>
    <PageHeader :title="header ? `Dispatch ${header.document_number}` : 'Dispatch'" :subtitle="header ? `${header.source_warehouse} → ${header.destination_warehouse}` : null"
                :back="`/r/transfers/${transferId}`" />
    <ErrorSummary :error="loadError ?? error" />

    <div class="card mb-4">
        <label for="dispatch-scan" class="label">Scan location or lot to confirm a pick</label>
        <BarcodeInput id="dispatch-scan" v-model="code" autofocus @scan="scan" />
    </div>

    <p v-if="header && lines.length === 0" class="card text-sm text-ink-muted">Nothing is left to dispatch on this transfer.</p>
    <ul class="space-y-4">
        <li v-for="l in lines" :key="l.id" class="card">
            <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                <p class="font-semibold">{{ l.sku }} · {{ l.name }}</p>
                <p class="text-sm">To dispatch <strong class="tabular-nums">{{ formatQuantity(l.outstanding) }}</strong></p>
            </div>
            <p v-if="l.picks.length === 0" class="text-sm text-danger">No available stock in the source warehouse.</p>
            <ul class="space-y-2">
                <li v-for="(p, i) in l.picks" :key="i" :class="['grid items-center gap-2 rounded-brand p-2 sm:grid-cols-[1fr_8rem]', p.confirmed ? 'bg-success/10' : 'bg-surface-muted']">
                    <div class="text-sm">
                        <p class="font-medium">{{ p.confirmed ? '✓ ' : '' }}{{ p.location_path }}</p>
                        <p class="text-ink-muted">
                            <template v-if="p.lot_number">Lot {{ p.lot_number }}<template v-if="p.expiry_date"> · exp. {{ formatDate(p.expiry_date) }}</template> · </template>
                            {{ formatQuantity(p.available) }} {{ p.reserved ? 'reserved for this transfer' : 'available' }}
                        </p>
                    </div>
                    <div v-if="!isSerial(l)">
                        <label :for="`q-${l.id}-${i}`" class="sr-only">Quantity from {{ p.location_path }}</label>
                        <input :id="`q-${l.id}-${i}`" v-model="p.quantity" class="input text-right tabular-nums" inputmode="decimal">
                    </div>
                    <div v-else class="sm:col-span-2">
                        <label :for="`s-${l.id}-${i}`" class="label">Serials taken from here (one per line)</label>
                        <textarea :id="`s-${l.id}-${i}`" v-model="p.serials" class="input min-h-20 font-mono" spellcheck="false" />
                    </div>
                </li>
            </ul>
        </li>
    </ul>

    <section v-if="lines.length" class="card mt-4 grid gap-3 md:grid-cols-3">
        <div>
            <label for="d-carrier" class="label">Carrier</label>
            <ReferencePicker id="d-carrier" v-model="shipping.carrier_partner_id" resource="carriers" />
        </div>
        <div><label for="d-tracking" class="label">Tracking reference</label><input id="d-tracking" v-model="shipping.tracking_reference" class="input"></div>
        <div><label for="d-arrival" class="label">Expected arrival</label><input id="d-arrival" v-model="shipping.expected_arrival" type="date" class="input"></div>
        <div><label for="d-seal" class="label">Seal</label><input id="d-seal" v-model="shipping.seal_reference" class="input"></div>
        <div><label for="d-vehicle" class="label">Vehicle</label><input id="d-vehicle" v-model="shipping.vehicle_reference" class="input"></div>
        <div class="flex items-end">
            <UiButton variant="accent" class="w-full" :loading="busy" :disabled="total === 0" @click="dispatch">{{ busy ? 'Posting…' : 'Post dispatch' }}</UiButton>
        </div>
    </section>
</template>
