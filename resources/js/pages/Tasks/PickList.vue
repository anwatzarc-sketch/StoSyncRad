<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import BarcodeInput from '@/components/BarcodeInput.vue';
import { get, post } from '@/lib/api';
import { formatDate, formatQuantity } from '@/lib/format';
import { notify } from '@/lib/toast';

/**
 * Picking (Chapter 08 §8.9, diagram "FEFO picking"): lines in walking order with the lot the
 * system reserved; scan the location to confirm, key the picked quantity, explain short picks.
 */
interface Line { id: string; location_path: string | null; location_barcode: string | null; sku: string; name: string; lot_number: string | null; expiry_date: string | null;
    serial_number: string | null; suggested_base_quantity: string; picked_base_quantity: string | null; status: string }

const props = defineProps<{ pickListId: string }>();
const head = ref<Record<string, any> | null>(null);
const lines = ref<Line[]>([]);
const entries = reactive<Record<string, { quantity: string; reason: string; confirmed: boolean }>>({});
const code = ref('');
const busy = ref(false);
const error = ref<unknown>(null);

const open = computed(() => lines.value.filter((l) => l.status === 'pending'));
const ready = computed(() => open.value.filter((l) => entries[l.id]?.quantity !== '' && (Number(entries[l.id].quantity) >= Number(l.suggested_base_quantity) || entries[l.id].reason.trim())));

async function load() {
    try {
        const sheet = (await get(`/api/v1/pick-lists/${props.pickListId}/sheet`)).data;
        head.value = sheet.pick_list;
        lines.value = sheet.lines;
        for (const l of sheet.lines as Line[]) {
            entries[l.id] ??= { quantity: '', reason: '', confirmed: false };
        }
    } catch (e) {
        error.value = e;
    }
}

function scan(value: string) {
    const v = value.trim();
    const hit = open.value.find((l) => !entries[l.id].confirmed && (l.location_barcode === v || l.location_path === v || l.lot_number === v || l.serial_number === v));
    code.value = '';
    if (!hit) {
        notify(`“${v}” is not the next pick here.`, 'error');

        return;
    }
    entries[hit.id].confirmed = true;
    if (entries[hit.id].quantity === '') {
        entries[hit.id].quantity = hit.suggested_base_quantity;
    }
    document.getElementById(`pick-${hit.id}`)?.focus();
}

async function record(complete: boolean) {
    busy.value = true;
    error.value = null;
    try {
        const picks = ready.value.map((l) => ({ line_id: l.id, picked_quantity: entries[l.id].quantity, short_pick_reason: entries[l.id].reason || null }));
        if (picks.length) {
            await post(`/api/v1/pick-lists/${props.pickListId}/picks`, { lines: picks });
        }
        if (complete) {
            await post(`/api/v1/pick-lists/${props.pickListId}/complete`, {});
            notify('Pick list completed.');
            router.visit(`/r/pick-lists/${props.pickListId}`);

            return;
        }
        notify('Picks recorded.');
        await load();
    } catch (e) {
        error.value = e;
    } finally {
        busy.value = false;
    }
}

onMounted(load);
</script>

<template>
    <PageHeader :title="head ? `Pick ${head.document_number}` : 'Pick'" :back="`/r/pick-lists/${pickListId}`" />
    <ErrorSummary :error="error" />

    <div class="card sticky top-16 z-10 mb-4">
        <label for="pick-scan" class="label">Scan the location (or lot / serial) you are picking from</label>
        <BarcodeInput id="pick-scan" v-model="code" autofocus @scan="scan" />
        <p class="help">{{ open.length }} line(s) open</p>
    </div>

    <ul class="space-y-2">
        <li v-for="l in lines" :key="l.id" :class="['card', l.status !== 'pending' ? 'opacity-60' : entries[l.id]?.confirmed ? 'border-success' : '']">
            <div class="grid items-center gap-3 sm:grid-cols-[1fr_9rem]">
                <div class="min-w-0 text-sm">
                    <p class="font-mono text-xs text-ink-muted">{{ l.location_path }}</p>
                    <p class="font-semibold">{{ l.sku }} · {{ l.name }}</p>
                    <p class="text-ink-muted">
                        Pick <strong class="text-ink">{{ formatQuantity(l.suggested_base_quantity) }}</strong>
                        <template v-if="l.lot_number"> · lot {{ l.lot_number }}<template v-if="l.expiry_date"> (exp. {{ formatDate(l.expiry_date) }})</template></template>
                        <template v-if="l.serial_number"> · serial {{ l.serial_number }}</template>
                        <template v-if="l.status !== 'pending'"> · {{ l.status }} {{ formatQuantity(l.picked_base_quantity) }}</template>
                    </p>
                </div>
                <div v-if="l.status === 'pending'">
                    <label :for="`pick-${l.id}`" class="sr-only">Picked quantity</label>
                    <input :id="`pick-${l.id}`" v-model="entries[l.id].quantity" class="input text-right text-lg tabular-nums" inputmode="decimal" placeholder="Picked">
                </div>
            </div>
            <div v-if="l.status === 'pending' && entries[l.id].quantity !== '' && Number(entries[l.id].quantity) < Number(l.suggested_base_quantity)" class="mt-2">
                <label :for="`short-${l.id}`" class="label">Why is it short?</label>
                <input :id="`short-${l.id}`" v-model="entries[l.id].reason" class="input" placeholder="e.g. damaged, not found at location">
            </div>
        </li>
    </ul>

    <div class="card mt-4 flex flex-wrap justify-end gap-2">
        <UiButton :loading="busy" :disabled="ready.length === 0" @click="record(false)">Record {{ ready.length }} pick(s)</UiButton>
        <UiButton variant="primary" :loading="busy" :disabled="open.length !== ready.length" @click="record(true)">Record & complete</UiButton>
    </div>
</template>
