<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import ReferencePicker from '@/components/ui/ReferencePicker.vue';
import BarcodeInput from '@/components/BarcodeInput.vue';
import { ApiError, get, post } from '@/lib/api';
import { formatDate, formatQuantity } from '@/lib/format';
import { notify } from '@/lib/toast';
import { useShared } from '@/composables/useShared';

/**
 * Stocktake counting (Chapter 08 §8.13): walk the locations, scan a location to jump to it, key the
 * count with the numeric keypad. Blind counts never show book quantities unless the person may see
 * them. During a recount round only the flagged lines are shown and saved as recounts.
 */
interface Line {
    id: string; location_path: string | null; location_barcode: string | null; sku: string; name: string; lot_number: string | null; expiry_date: string | null;
    snapshot_quantity?: string; counted_quantity: string | null; recount_quantity: string | null; count_status: string; is_unexpected: boolean;
}

const props = defineProps<{ stocktakeId: string }>();
const { can } = useShared();
const head = ref<Record<string, any> | null>(null);
const lines = ref<Line[]>([]);
const entries = reactive<Record<string, { quantity: string; excluded: boolean }>>({});
const found = ref<Array<{ item_id: string | null; storage_location_id: string | null; quantity: string }>>([]);
const filter = ref<string | null>(null);
const code = ref('');
const saving = ref(false);
const error = ref<unknown>(null);

const recountRound = computed(() => lines.value.some((l) => l.count_status === 'recount_required'));
const workLines = computed(() => lines.value.filter((l) => (recountRound.value ? l.count_status === 'recount_required' : true))
    .filter((l) => !filter.value || l.location_path === filter.value));
const pending = computed(() => workLines.value.filter((l) => !entries[l.id]?.excluded && (entries[l.id]?.quantity ?? '') === '').length);
const dirty = computed(() => Object.entries(entries).filter(([id, e]) => e.excluded || (e.quantity !== '' && e.quantity !== original(id))));

function original(id: string): string {
    const l = lines.value.find((x) => x.id === id);

    return (recountRound.value ? l?.recount_quantity : l?.counted_quantity) ?? '';
}

async function load() {
    try {
        const sheet = (await get(`/api/v1/stocktakes/${props.stocktakeId}/count-sheet`)).data;
        head.value = sheet.stocktake;
        lines.value = sheet.lines;
        for (const l of sheet.lines as Line[]) {
            entries[l.id] = { quantity: (recountRound.value ? l.recount_quantity : l.counted_quantity) ?? '', excluded: l.count_status === 'excluded' };
        }
    } catch (e) {
        error.value = e;
    }
}

async function scan(value: string) {
    const v = value.trim();
    const hit = lines.value.find((l) => l.location_barcode === v || l.location_path === v || l.location_path?.endsWith(`/${v}`));
    code.value = '';
    if (!hit) {
        notify(`Location “${v}” has nothing on this count. Add found stock below if items are there.`, 'info');

        return;
    }
    filter.value = hit.location_path;
    await nextTick();
    document.getElementById(`count-${workLines.value[0]?.id}`)?.focus();
}

async function save(finish = false) {
    saving.value = true;
    error.value = null;
    try {
        const counts = dirty.value.map(([id, e]) => ({ line_id: id, quantity: e.excluded ? null : e.quantity, excluded: e.excluded }));
        const unexpected = found.value.filter((f) => f.item_id && f.storage_location_id && Number(f.quantity) > 0);
        if (counts.length || unexpected.length) {
            await post(`/api/v1/stocktakes/${props.stocktakeId}/${recountRound.value ? 'recounts' : 'counts'}`, { counts, unexpected, version: head.value?.version });
        }
        if (finish) {
            await post(`/api/v1/stocktakes/${props.stocktakeId}/finish-counting`, {});
            notify('Counting finished; variances are ready for review.');
            router.visit(`/r/stocktakes/${props.stocktakeId}`);

            return;
        }
        notify('Counts saved.');
        found.value = [];
        await load();
    } catch (e) {
        error.value = e;
        if (e instanceof ApiError && e.status === 409) {
            await load();
        }
    } finally {
        saving.value = false;
    }
}

onMounted(load);
</script>

<template>
    <PageHeader :title="head ? `Count ${head.document_number}` : 'Count'" :subtitle="head ? (recountRound ? 'Recount round — only flagged lines' : head.is_blind ? 'Blind count' : null) : null"
                :back="`/r/stocktakes/${stocktakeId}`" />
    <ErrorSummary :error="error" />
    <p v-if="head?.instructions" class="card mb-4 text-sm">{{ head.instructions }}</p>

    <div class="card sticky top-16 z-10 mb-4 grid gap-3 md:grid-cols-[1fr_auto] md:items-end">
        <div>
            <label for="count-scan" class="label">Scan a location</label>
            <BarcodeInput id="count-scan" v-model="code" autofocus @scan="scan" />
        </div>
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <span v-if="filter" class="rounded-full bg-accent/10 px-3 py-1 font-medium text-accent">{{ filter }}
                <button type="button" class="ml-1 underline" @click="filter = null">all</button></span>
            <span class="text-ink-muted">{{ pending }} to count</span>
        </div>
    </div>

    <ul class="space-y-2">
        <li v-for="l in workLines" :key="l.id" :class="['card grid items-center gap-3 sm:grid-cols-[1fr_9rem_auto]', entries[l.id]?.excluded ? 'opacity-60' : '']">
            <div class="min-w-0 text-sm">
                <p class="font-mono text-xs text-ink-muted">{{ l.location_path }}</p>
                <p class="font-semibold">{{ l.sku }} · {{ l.name }}</p>
                <p class="text-ink-muted">
                    <template v-if="l.lot_number">Lot {{ l.lot_number }}<template v-if="l.expiry_date"> · exp. {{ formatDate(l.expiry_date) }}</template></template>
                    <template v-if="head?.show_book_quantity && l.snapshot_quantity !== undefined"> · book {{ formatQuantity(l.snapshot_quantity) }}</template>
                    <template v-if="recountRound && l.counted_quantity !== null"> · first count {{ formatQuantity(l.counted_quantity) }}</template>
                </p>
            </div>
            <div>
                <label :for="`count-${l.id}`" class="sr-only">Counted quantity for {{ l.sku }} at {{ l.location_path }}</label>
                <input :id="`count-${l.id}`" v-model="entries[l.id].quantity" class="input text-right text-lg tabular-nums" inputmode="decimal" enterkeyhint="next"
                       :disabled="entries[l.id].excluded" placeholder="Count">
            </div>
            <label class="flex min-h-11 items-center gap-2 text-sm"><input v-model="entries[l.id].excluded" type="checkbox" class="size-5 accent-brand"> Can't count</label>
        </li>
    </ul>

    <section v-if="!recountRound" class="card mt-4 space-y-3">
        <h2 class="font-semibold">Stock found that is not on the list</h2>
        <div v-for="(f, i) in found" :key="i" class="grid gap-2 md:grid-cols-[1fr_1fr_8rem_auto] md:items-end">
            <div><label :for="`fi-${i}`" class="label">Item</label><ReferencePicker :id="`fi-${i}`" v-model="f.item_id" resource="items" /></div>
            <div><label :for="`fl-${i}`" class="label">Location</label>
                <ReferencePicker :id="`fl-${i}`" v-model="f.storage_location_id" resource="storage-locations" :filters="{ warehouse_id: head?.warehouse_id }" /></div>
            <div><label :for="`fq-${i}`" class="label">Quantity</label><input :id="`fq-${i}`" v-model="f.quantity" class="input text-right" inputmode="decimal"></div>
            <button type="button" class="btn btn-ghost text-danger" @click="found.splice(i, 1)">Remove</button>
        </div>
        <UiButton @click="found.push({ item_id: null, storage_location_id: null, quantity: '' })">+ Found stock</UiButton>
    </section>

    <div class="card mt-4 flex flex-wrap justify-end gap-2">
        <UiButton :loading="saving" @click="save(false)">Save counts</UiButton>
        <UiButton v-if="can('stocktaking.stocktake.count')" variant="primary" :loading="saving" :disabled="pending > 0" @click="save(true)">Save & finish counting</UiButton>
    </div>
</template>
