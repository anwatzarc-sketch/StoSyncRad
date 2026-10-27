<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import ReferencePicker from '@/components/ui/ReferencePicker.vue';
import AppIcon from '@/components/ui/AppIcon.vue';
import BarcodeInput from '@/components/BarcodeInput.vue';
import { get } from '@/lib/api';
import { formatDate, formatQuantity } from '@/lib/format';
import { notify } from '@/lib/toast';
import { usePosting } from '@/composables/usePosting';
import { useShared } from '@/composables/useShared';
import { scanLocation } from '@/lib/scan';
import type { NextAction } from '@/types/shared';

/**
 * Put away (Chapter 06 §6.4, Chapter 08 §8.3): the open put-away tasks in your warehouses, yours first.
 * Pick one, scan the destination bin (or keep the suggested one), confirm the quantity, put it away —
 * each put-away posts a movement with its own idempotency key.
 */
interface Task {
    id: string; document_number: string; version: number; warehouse_id: string; warehouse: string; sku: string; name: string; lot_number: string | null;
    expiry_date: string | null; serial_number: string | null; quantity: string; stock_condition: string; from_location_path: string | null;
    suggested_storage_location_id: string | null; suggested_location_path: string | null; suggested_location_barcode: string | null;
    goods_receipt_number: string | null; mine: boolean;
}

const tasks = ref<Task[]>([]);
const loading = ref(true);
const loadError = ref<unknown>(null);
const current = ref<Task | null>(null);
const destination = ref<string | null>(null);
const destinationPath = ref<string | null>(null);
const { can } = useShared();
const quantity = ref('');
const code = ref('');
const posting = ref<ReturnType<typeof usePosting> | null>(null);

const others = computed(() => tasks.value.filter((t) => t !== current.value));
/** Next action: put the open task away, else start the oldest task (yours first), else go back to receiving. */
const nextActions = computed<NextAction[]>(() => {
    if (loading.value) {
        return [];
    }
    if (current.value) {
        return [{ label: `Put away into ${destinationPath.value ?? 'a bin'}`, icon: 'boxes', onClick: () => void putAway(),
            hint: `${current.value.sku}: scan the bin you are putting it into${current.value.suggested_location_path ? `, or keep the suggested ${current.value.suggested_location_path}` : ''}.` }];
    }
    const first = tasks.value[0];

    return first
        ? [{ label: `Start with ${first.sku}`, icon: 'arrow-right', onClick: () => open(first), hint: `${tasks.value.length} task(s) waiting. Or scan an item, lot or task number.` }]
        : [{ label: 'Receive more goods', icon: 'inbox', href: '/tasks/receiving', hint: 'Nothing is waiting to be put away.' }];
});

async function load() {
    loading.value = true;
    try {
        tasks.value = (await get('/api/v1/putaway-tasks/sheet')).data.tasks;
    } catch (e) {
        loadError.value = e;
    } finally {
        loading.value = false;
    }
}

function open(task: Task) {
    current.value = task;
    destination.value = task.suggested_storage_location_id;
    destinationPath.value = task.suggested_location_path;
    quantity.value = '';
    posting.value = usePosting(`putaway:${task.id}`);
}

/** One scan box: a bin sets where the open task goes; an item, lot, serial or task number opens that task. */
async function scan(value: string) {
    const v = value.trim();
    code.value = '';
    if (current.value && current.value.suggested_location_barcode === v) {
        destination.value = current.value.suggested_storage_location_id;
        destinationPath.value = current.value.suggested_location_path;
        notify(`Bin ${current.value.suggested_location_path} confirmed.`);

        return;
    }
    const task = tasks.value.find((t) => t.sku === v || t.lot_number === v || t.serial_number === v || t.document_number === v);
    if (task) {
        open(task);

        return;
    }
    const bin = current.value ? await scanLocation(v) : null;
    if (bin && bin.warehouse_id === current.value?.warehouse_id) {
        destination.value = bin.id;
        destinationPath.value = bin.path;
        notify(`Bin ${bin.path} selected.`);
    } else {
        notify(bin ? `${bin.path} is in another warehouse.` : `Nothing to put away matches “${v}”.`, 'error');
    }
}

async function putAway() {
    if (!current.value || !destination.value || !posting.value) {
        return;
    }
    const done = await posting.value.post(`/api/v1/putaway-tasks/${current.value.id}/post`, { to_storage_location_id: destination.value, quantity: quantity.value || null });
    if (done !== null) {
        notify(`${current.value.sku} put away.`);
        current.value = null;
        await load();
    }
}

onMounted(load);
</script>

<template>
    <PageHeader title="Put away" subtitle="Move received goods from the dock to their bins" back="/work/receiving" :next="nextActions" />
    <ErrorSummary :error="loadError ?? posting?.error" />

    <div class="card mb-4">
        <label for="putaway-scan" class="label">{{ current ? 'Scan the destination bin — or another item to switch' : 'Scan an item, lot or task' }}</label>
        <BarcodeInput id="putaway-scan" v-model="code" autofocus @scan="scan" />
    </div>

    <section v-if="current" class="card mb-4 border-brand/40">
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
            <div>
                <p class="font-semibold">{{ current.sku }} · {{ current.name }}</p>
                <p class="text-sm text-ink-muted">
                    {{ current.document_number }}<template v-if="current.goods_receipt_number"> · from {{ current.goods_receipt_number }}</template>
                    <template v-if="current.lot_number"> · lot <strong>{{ current.lot_number }}</strong><template v-if="current.expiry_date"> (exp. {{ formatDate(current.expiry_date) }})</template></template>
                    <template v-if="current.serial_number"> · serial <strong>{{ current.serial_number }}</strong></template>
                </p>
            </div>
            <p class="text-sm">Quantity <strong class="tabular-nums">{{ formatQuantity(current.quantity) }}</strong></p>
        </div>
        <p class="mb-3 flex items-center gap-2 text-sm">
            <AppIcon name="route" :size="16" class="text-ink-muted" />
            {{ current.from_location_path ?? 'Receiving area' }}
            <AppIcon name="arrow-right" :size="14" class="text-ink-muted" />
            <strong>{{ destinationPath ?? 'scan the bin' }}</strong>
        </p>
        <div class="grid gap-3 sm:grid-cols-2">
            <div v-if="can('organization.storage_location.read')">
                <label for="putaway-to" class="label">Or choose the bin</label>
                <ReferencePicker id="putaway-to" v-model="destination" resource="storage-locations" :filters="{ warehouse_id: current.warehouse_id }"
                                 @picked="destinationPath = $event?.full_path ?? null" />
            </div>
            <div>
                <label for="putaway-qty" class="label">Quantity (blank = all)</label>
                <input id="putaway-qty" v-model="quantity" class="input text-right tabular-nums" inputmode="decimal" :placeholder="formatQuantity(current.quantity)">
            </div>
        </div>
        <div class="mt-4 flex flex-wrap justify-end gap-2">
            <UiButton @click="current = null">Back to the list</UiButton>
            <UiButton variant="primary" :loading="posting?.busy" :disabled="!destination" @click="putAway">{{ posting?.busy ? 'Posting…' : 'Put away' }}</UiButton>
        </div>
    </section>

    <div v-if="loading && !tasks.length" class="space-y-3" aria-busy="true">
        <div v-for="n in 3" :key="n" class="h-20 animate-pulse rounded-brand bg-surface" />
    </div>
    <p v-else-if="!tasks.length" class="card flex items-center gap-3 text-sm text-ink-muted">
        <AppIcon name="check" class="text-success" /> Nothing is waiting to be put away.
    </p>
    <ul v-else class="space-y-2">
        <li v-for="t in others" :key="t.id">
            <button type="button" class="card flex w-full flex-wrap items-center gap-3 text-left transition hover:shadow-md" @click="open(t)">
                <span class="min-w-0 flex-1">
                    <span class="block font-semibold">{{ t.sku }} · {{ t.name }}<span v-if="t.mine" class="ml-2 rounded-full bg-accent/15 px-2 text-xs font-semibold text-accent">Yours</span></span>
                    <span class="block text-sm text-ink-muted">
                        {{ formatQuantity(t.quantity) }}<template v-if="t.lot_number"> · lot {{ t.lot_number }}</template> · {{ t.from_location_path ?? 'Receiving' }} → {{ t.suggested_location_path ?? '—' }}
                    </span>
                </span>
                <span class="text-xs text-ink-muted">{{ t.warehouse }}</span>
                <AppIcon name="chevron-right" class="text-ink-muted" />
            </button>
        </li>
    </ul>
</template>
