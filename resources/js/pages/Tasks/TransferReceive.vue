<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import EnumSelect from '@/components/ui/EnumSelect.vue';
import ReferencePicker from '@/components/ui/ReferencePicker.vue';
import BarcodeInput from '@/components/BarcodeInput.vue';
import { get } from '@/lib/api';
import { formatDate, formatQuantity, roundDecimal } from '@/lib/format';
import { notify } from '@/lib/toast';
import { usePosting } from '@/composables/usePosting';

/**
 * Transfer receipt with batch verification (Chapter 08 §8.6): scan each arriving lot/serial — it must
 * match what was dispatched — record accepted, damaged and excess quantities, and either leave the
 * rest in transit or report it short (a discrepancy for review).
 */
interface Row {
    id: string; dispatch_number: string; sku: string; name: string; lot_number: string | null; expiry_date: string | null; serial_number: string | null;
    outstanding: string; accepted: string; damaged: string; excess: string; shortage: boolean; condition: string; location: string | null; scanned: string | null; mismatch: boolean;
}

const props = defineProps<{ transferId: string }>();
const header = ref<Record<string, any> | null>(null);
const rows = ref<Row[]>([]);
const code = ref('');
const loadError = ref<unknown>(null);
const { busy, error, post } = usePosting(`receive:${props.transferId}`);

const short = (r: Row) => Number(roundDecimal(String(Number(r.outstanding) - Number(r.accepted || 0) - Number(r.damaged || 0)), 6)) > 0;
const touched = computed(() => rows.value.filter((r) => Number(r.accepted) > 0 || Number(r.damaged) > 0 || Number(r.excess) > 0 || r.shortage));
const mismatches = computed(() => rows.value.filter((r) => r.mismatch).length);

onMounted(async () => {
    try {
        const sheet = (await get(`/api/v1/transfers/${props.transferId}/receipt-sheet`)).data;
        header.value = sheet.transfer;
        rows.value = sheet.lines.map((l: any) => ({ ...l, accepted: '0', damaged: '0', excess: '0', shortage: false, condition: 'good', location: null, scanned: null, mismatch: false }));
    } catch (e) {
        loadError.value = e;
    }
});

function scan(value: string) {
    const v = value.trim();
    const match = rows.value.find((r) => !r.scanned && ((r.serial_number && r.serial_number === v) || (r.lot_number && r.lot_number === v)))
        ?? rows.value.find((r) => (r.serial_number ?? r.lot_number) === v);
    if (match) {
        match.scanned = v;
        match.mismatch = false;
        if (Number(match.accepted) === 0) {
            match.accepted = match.serial_number ? '1' : match.outstanding;
        }
        notify(`${match.sku}${match.lot_number ? ` · lot ${match.lot_number}` : ''} verified.`);
    } else {
        notify(`“${v}” was not dispatched on this transfer — keep it aside and report it.`, 'error');
    }
    code.value = '';
}

function acceptAll() {
    rows.value.forEach((r) => {
        if (!r.lot_number && !r.serial_number) {
            r.accepted = r.outstanding;
        }
    });
}

async function receive() {
    const lines = touched.value.map((r) => ({
        transfer_dispatch_line_id: r.id, accepted_quantity: r.accepted || '0', damaged_quantity: r.damaged || '0', excess_quantity: r.excess || '0',
        report_shortage: r.shortage, receiving_condition: r.condition, destination_storage_location_id: r.location,
        scanned_lot_number: r.lot_number ? r.scanned : null, scanned_serial_number: r.serial_number ? r.scanned : null,
    }));
    if (await post(`/api/v1/transfers/${props.transferId}/receive`, { lines, version: header.value?.version })) {
        notify('Receipt posted.');
        router.visit(`/r/transfers/${props.transferId}`);
    }
}
</script>

<template>
    <PageHeader :title="header ? `Receive ${header.document_number}` : 'Receive'" :subtitle="header ? `${header.source_warehouse} → ${header.destination_warehouse}` : null"
                :back="`/r/transfers/${transferId}`" />
    <ErrorSummary :error="loadError ?? error" />

    <div class="card mb-4 grid gap-3 md:grid-cols-[1fr_auto] md:items-end">
        <div>
            <label for="receive-scan" class="label">Scan each lot or serial as it is unloaded</label>
            <BarcodeInput id="receive-scan" v-model="code" autofocus @scan="scan" />
        </div>
        <UiButton @click="acceptAll">Accept untracked lines in full</UiButton>
    </div>

    <p v-if="header && rows.length === 0" class="card text-sm text-ink-muted">Nothing is in transit to this warehouse for this transfer.</p>
    <ul class="space-y-3">
        <li v-for="r in rows" :key="r.id" :class="['card', r.scanned ? 'border-success' : '']">
            <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                <div>
                    <p class="font-semibold">{{ r.sku }} · {{ r.name }}</p>
                    <p class="text-sm text-ink-muted">
                        {{ r.dispatch_number }}
                        <template v-if="r.lot_number"> · lot <strong>{{ r.lot_number }}</strong><template v-if="r.expiry_date"> (exp. {{ formatDate(r.expiry_date) }})</template></template>
                        <template v-if="r.serial_number"> · serial <strong>{{ r.serial_number }}</strong></template>
                        <span v-if="(r.lot_number || r.serial_number) && !r.scanned" class="text-warning"> · not yet scanned</span>
                        <span v-if="r.scanned" class="text-success"> · verified</span>
                    </p>
                </div>
                <p class="text-sm">In transit <strong class="tabular-nums">{{ formatQuantity(r.outstanding) }}</strong></p>
            </div>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <div><label :for="`acc-${r.id}`" class="label">Accepted</label><input :id="`acc-${r.id}`" v-model="r.accepted" class="input text-right tabular-nums" inputmode="decimal"></div>
                <div><label :for="`dam-${r.id}`" class="label">Damaged</label><input :id="`dam-${r.id}`" v-model="r.damaged" class="input text-right tabular-nums" inputmode="decimal"></div>
                <div><label :for="`exc-${r.id}`" class="label">Excess found</label><input :id="`exc-${r.id}`" v-model="r.excess" class="input text-right tabular-nums" inputmode="decimal"></div>
                <div>
                    <label :for="`cond-${r.id}`" class="label">Condition</label>
                    <EnumSelect :id="`cond-${r.id}`" enum-code="receiving_condition" :model-value="r.condition" @update:model-value="r.condition = ($event as string) ?? 'good'" />
                </div>
                <div>
                    <label :for="`loc-${r.id}`" class="label">Put into</label>
                    <ReferencePicker :id="`loc-${r.id}`" v-model="r.location" resource="storage-locations" :filters="{ warehouse_id: header?.destination_warehouse_id }" />
                </div>
            </div>
            <label v-if="short(r) && (Number(r.accepted) > 0 || Number(r.damaged) > 0)" class="mt-3 flex min-h-11 items-center gap-3 text-sm">
                <input v-model="r.shortage" type="checkbox" class="size-5 accent-brand"> Report the remaining quantity as short (opens a discrepancy)
            </label>
        </li>
    </ul>

    <div v-if="rows.length" class="card mt-4 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-ink-muted">{{ touched.length }} line(s) to receive<template v-if="mismatches"> · {{ mismatches }} mismatched scans</template></p>
        <UiButton variant="accent" :loading="busy" :disabled="touched.length === 0" @click="receive">{{ busy ? 'Posting…' : 'Post receipt' }}</UiButton>
    </div>
</template>
