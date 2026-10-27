<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import EnumSelect from '@/components/ui/EnumSelect.vue';
import ReferencePicker from '@/components/ui/ReferencePicker.vue';
import AppIcon from '@/components/ui/AppIcon.vue';
import BarcodeInput from '@/components/BarcodeInput.vue';
import { ApiError, api, get } from '@/lib/api';
import { loadEnums } from '@/lib/meta';
import { scanLocation } from '@/lib/scan';
import { formatDate, formatQuantity } from '@/lib/format';
import { notify } from '@/lib/toast';
import { usePosting } from '@/composables/usePosting';
import { useShared } from '@/composables/useShared';
import type { NextAction } from '@/types/shared';

/**
 * Receive goods, step 2 — the guided dock flow (Chapter 08 §8.3): confirm the warehouse, scan each
 * item (it must be on the order), enter quantity, lot/expiry/serials where the item needs them,
 * condition and rejections, answer the quality checklist, choose where it goes, review and finish.
 * Finishing saves the receipt (POST /goods-receipts), records each quality check, submits it and —
 * when no approval is needed and the person may post — posts it with an idempotency key.
 */
interface SheetLine {
    purchase_order_line_id: string; line_no: number; item_id: string; item_variant_id: string | null; sku: string; name: string; barcodes: string[];
    uom_id: string | null; uom: string | null; base_uom: string | null; ordered_quantity: string; remaining_quantity: string;
    tracking_mode: 'none' | 'lot' | 'serial'; expiry_policy: string; lot_number_policy: string | null; qc_policy: string;
    qc_checks: Array<{ code: string; label: string; type: 'boolean' | 'numeric' | 'text' | 'choice'; required?: boolean; choices?: string[]; min?: string; max?: string }>;
    suggested_storage_location_id: string | null; suggested_location_path: string | null;
}
interface Entry {
    key: number; line: SheetLine; received: string; rejected: string; reason: string | null; condition: string;
    lot: string; manufactured: string; expiry: string; serials: string; location: string | null; locationPath: string | null; locationCode: string;
    notes: string; answers: Record<string, string>; inspected: string; failed: string;
}

const props = defineProps<{ purchaseOrderId: string }>();
const { can } = useShared();

const order = ref<Record<string, any> | null>(null);
const lines = ref<SheetLine[]>([]);
const reasons = ref<Array<{ id: string; label: string }>>([]);
const entries = ref<Entry[]>([]);
const deliveryNote = ref('');
const warehouseConfirmed = ref(false);
const code = ref('');
const loadError = ref<unknown>(null);
const saveError = ref<unknown>(null);
const saving = ref(false);
const receiptId = ref<string | null>(null);
const result = ref<{ id: string; document_number: string; status: string; status_label?: string } | null>(null);
let nextKey = 1;
const posting = usePosting(`receive-goods:${props.purchaseOrderId}`);

const fieldErrors = computed(() => (saveError.value instanceof ApiError ? saveError.value.fieldErrors() : {}));
const pending = computed(() => lines.value.filter((l) => !entries.value.some((e) => e.line.purchase_order_line_id === l.purchase_order_line_id)));
const needsQc = (e: Entry) => e.line.qc_policy === 'inspect_on_receipt' && e.line.qc_checks.length > 0;
const asksExpiry = (e: Entry) => e.line.expiry_policy === 'tracked_required' || e.line.expiry_policy === 'tracked_optional';
const err = (i: number, field: string) => fieldErrors.value[`lines.${i}.${field}`];
const focus = (id: string) => {
    const el = document.getElementById(id);
    el?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    el?.focus();
};
/** Next action of the dock flow: confirm the warehouse, scan what is still on the order, then finish. (When done, the result panel says what follows.) */
const nextActions = computed<NextAction[]>(() => {
    if (!order.value || result.value) {
        return [];
    }
    if (!warehouseConfirmed.value) {
        return [{ label: 'Confirm the warehouse', icon: 'building', onClick: () => focus('receive-warehouse'),
            hint: `Check the goods are arriving at ${order.value.warehouse_name ?? order.value.warehouse}.` }];
    }
    const scanNext: NextAction = { label: 'Scan the next item', icon: 'scan', onClick: () => focus('receive-scan'),
        hint: `${pending.value.length} order line(s) not received yet${entries.value.length ? `; ${entries.value.length} on this receipt` : ''}.` };

    return entries.value.length
        ? [{ label: 'Review and finish', icon: 'check', onClick: () => focus('receive-finish'), hint: `${entries.value.length} line(s) on this receipt. Finish to submit${can('receiving.goods_receipt.post') ? ' and post' : ''} it.` },
            ...(pending.value.length ? [{ ...scanNext, hint: undefined }] : [])]
        : [scanNext];
});

onMounted(async () => {
    await loadEnums().catch(() => undefined);
    try {
        const sheet = (await get(`/api/v1/purchase-orders/${props.purchaseOrderId}/receiving-sheet`)).data;
        order.value = sheet.purchase_order;
        lines.value = sheet.lines;
        reasons.value = sheet.rejection_reasons;
    } catch (e) {
        loadError.value = e;
    }
});

function add(line: SheetLine): Entry {
    const existing = entries.value.find((e) => e.line.purchase_order_line_id === line.purchase_order_line_id);
    if (existing) {
        return existing;
    }
    const entry: Entry = {
        key: nextKey++, line, received: line.tracking_mode === 'serial' ? '' : plain(line.remaining_quantity), rejected: '0', reason: null, condition: 'good',
        lot: '', manufactured: '', expiry: '', serials: '', location: line.suggested_storage_location_id, locationPath: line.suggested_location_path,
        locationCode: '', notes: '', answers: Object.fromEntries(line.qc_checks.map((c) => [c.code, ''])), inspected: '', failed: '0',
    };
    entries.value.push(entry);

    return entry;
}

async function scan(value: string) {
    const v = value.trim();
    code.value = '';
    const line = lines.value.find((l) => l.barcodes.includes(v) || l.sku.toLowerCase() === v.toLowerCase());
    if (!line) {
        notify(`“${v}” is not on this order — keep it aside.`, 'error');

        return;
    }
    const entry = add(line);
    notify(`${line.sku} · ${line.name}`);
    await nextTick();
    document.getElementById(`qty-${entry.key}`)?.focus();
}

function remove(entry: Entry) {
    entries.value = entries.value.filter((e) => e !== entry);
}

/** "30.000000" → "30" for an editable default. */
const plain = (n: string) => (n.includes('.') ? n.replace(/\.?0+$/, '') : n);

async function scanBin(entry: Entry, value: string) {
    entry.locationCode = '';
    const bin = await scanLocation(value);
    if (bin && bin.warehouse_id === order.value?.warehouse_id) {
        entry.location = bin.id;
        entry.locationPath = bin.path;
    } else {
        notify(bin ? `${bin.path} is in another warehouse.` : `“${value.trim()}” is not a bin.`, 'error');
    }
}

function payload() {
    return {
        purchase_order_id: props.purchaseOrderId,
        supplier_delivery_reference: deliveryNote.value || null,
        lines: entries.value.map((e) => ({
            purchase_order_line_id: e.line.purchase_order_line_id, item_id: e.line.item_id, item_variant_id: e.line.item_variant_id, uom_id: e.line.uom_id,
            received_quantity: e.line.tracking_mode === 'serial' ? String(serialList(e).length) : e.received,
            rejected_quantity: e.rejected || '0', rejection_reason_lookup_value_id: Number(e.rejected) > 0 ? e.reason : null,
            receiving_condition: e.condition, lot_number: e.lot || null, manufacture_date: e.manufactured || null, expiry_date: e.expiry || null,
            serial_numbers: e.line.tracking_mode === 'serial' ? serialList(e) : null, destination_storage_location_id: e.location, inspection_notes: e.notes || null,
        })),
    };
}

const serialList = (e: Entry) => e.serials.split(/[\s,;]+/).map((s) => s.trim()).filter(Boolean);

/** Save (once), record the quality checks, then — when finishing — submit and post if allowed. */
async function finish(post: boolean) {
    saving.value = true;
    saveError.value = null;
    try {
        if (!receiptId.value) {
            const created = (await api('/api/v1/goods-receipts', { method: 'POST', body: payload() })).data;
            receiptId.value = created.id;
            const byLine = [...(created.lines ?? [])].sort((a: any, b: any) => a.line_no - b.line_no);
            for (const [i, e] of entries.value.entries()) {
                // An unanswered checklist is skipped on purpose: the stock then enters quarantine (QcPolicy::INSPECT_ON_RECEIPT).
                if (needsQc(e) && byLine[i] && Object.values(e.answers).some((a) => a !== '')) {
                    await api(`/api/v1/goods-receipts/${created.id}/lines/${byLine[i].id}/quality-check`, { method: 'POST', body: {
                        answers: e.answers, inspected_quantity: e.inspected || byLine[i].received_quantity, failed_quantity: e.failed || '0', notes: e.notes || null,
                    } });
                }
            }
        }
        let receipt = (await get(`/api/v1/goods-receipts/${receiptId.value}`)).data;
        if (post) {
            if (['draft', 'in_inspection'].includes(receipt.status)) {
                receipt = (await api(`/api/v1/goods-receipts/${receiptId.value}/submit`, { method: 'POST', body: { version: receipt.version } })).data;
            }
            if (receipt.status === 'approved' && can('receiving.goods_receipt.post')) {
                const posted = await posting.post(`/api/v1/goods-receipts/${receiptId.value}/post`, { version: receipt.version });
                if (posted === null) {
                    saveError.value = posting.error.value;

                    return;
                }
                receipt = (await get(`/api/v1/goods-receipts/${receiptId.value}`)).data;
            }
        }
        result.value = receipt;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    } catch (e) {
        saveError.value = e;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <PageHeader :title="order ? `Receive ${order.document_number}` : 'Receive goods'" :subtitle="order ? `${order.supplier ?? 'Supplier'} → ${order.warehouse_name ?? order.warehouse}` : null" back="/tasks/receiving" :next="nextActions" />
    <ErrorSummary :error="loadError ?? saveError" />

    <!-- Done -->
    <section v-if="result" class="card space-y-4 p-6">
        <div class="flex items-center gap-4">
            <span class="grid size-12 place-items-center rounded-full bg-success/10 text-success"><AppIcon name="check" /></span>
            <div>
                <p class="text-lg font-semibold">Receipt {{ result.document_number }}</p>
                <p class="text-sm text-ink-muted">
                    <template v-if="result.status === 'posted'">Posted — the stock is booked in. Put it away next.</template>
                    <template v-else-if="result.status === 'pending_approval'">Submitted — it now waits for approval before it can be posted.</template>
                    <template v-else-if="result.status === 'approved'">Approved — someone allowed to post receipts will post it.</template>
                    <template v-else>Saved as a draft. Open it to finish later.</template>
                </p>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            <UiButton v-if="result.status === 'posted' && can('receiving.putaway.post')" variant="primary" icon="boxes" href="/tasks/putaway">Put away now</UiButton>
            <UiButton :href="`/r/goods-receipts/${result.id}`">Open receipt</UiButton>
            <UiButton href="/tasks/receiving">Receive another order</UiButton>
        </div>
    </section>

    <template v-else-if="order">
        <!-- 1. Where -->
        <section class="card mb-4">
            <h2 class="mb-2 font-semibold"><span class="mr-2 text-ink-muted">1</span>Receiving into</h2>
            <label class="flex min-h-11 items-center gap-3 text-sm">
                <input id="receive-warehouse" v-model="warehouseConfirmed" type="checkbox" class="size-5 accent-brand">
                I am receiving into <strong>{{ order.warehouse_name ?? order.warehouse }}</strong> ({{ order.warehouse }})
            </label>
            <div class="mt-3 max-w-sm">
                <label for="delivery-note" class="label">Supplier delivery note (optional)</label>
                <input id="delivery-note" v-model="deliveryNote" class="input" autocomplete="off">
            </div>
        </section>

        <!-- 2. Scan -->
        <section class="card mb-4">
            <h2 class="mb-2 font-semibold"><span class="mr-2 text-ink-muted">2</span>Scan each item</h2>
            <BarcodeInput id="receive-scan" v-model="code" :disabled="!warehouseConfirmed" :autofocus="warehouseConfirmed" @scan="scan" />
            <p v-if="!warehouseConfirmed" class="help">Confirm the warehouse first.</p>
            <div v-if="pending.length" class="mt-4">
                <p class="mb-2 text-sm text-ink-muted">Still on the order — tap one that has no barcode:</p>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="l in pending"
                        :key="l.purchase_order_line_id"
                        type="button"
                        class="btn btn-secondary min-h-10 text-left"
                        :disabled="!warehouseConfirmed"
                        @click="add(l)"
                    >
                        {{ l.sku }} · {{ formatQuantity(l.remaining_quantity) }} {{ l.uom ?? '' }}
                    </button>
                </div>
            </div>
            <p v-if="lines.length === 0" class="mt-3 text-sm text-ink-muted">Everything on this order has been received.</p>
        </section>

        <!-- 3. Lines -->
        <ul class="space-y-3">
            <li v-for="(e, i) in entries" :key="e.key" class="card">
                <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                    <div>
                        <p class="font-semibold">{{ e.line.sku }} · {{ e.line.name }}</p>
                        <p class="text-sm text-ink-muted">Still to receive {{ formatQuantity(e.line.remaining_quantity) }} {{ e.line.uom ?? '' }}</p>
                    </div>
                    <button type="button" class="btn btn-ghost min-h-9 text-sm" @click="remove(e)"><AppIcon name="x" :size="16" /> Remove</button>
                </div>

                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div v-if="e.line.tracking_mode !== 'serial'">
                        <label :for="`qty-${e.key}`" class="label">Received ({{ e.line.uom ?? 'units' }})</label>
                        <input :id="`qty-${e.key}`" v-model="e.received" :class="['input text-right tabular-nums', { 'input-invalid': err(i, 'received_quantity') }]" inputmode="decimal">
                        <p v-if="err(i, 'received_quantity')" class="error-text">{{ err(i, 'received_quantity') }}</p>
                    </div>
                    <div v-if="e.line.tracking_mode === 'lot'">
                        <label :for="`lot-${e.key}`" class="label">Lot / batch{{ e.line.lot_number_policy === 'supplier_provided' ? '' : ' (blank = generate)' }}</label>
                        <input :id="`lot-${e.key}`" v-model="e.lot" :class="['input', { 'input-invalid': err(i, 'lot_number') }]" autocomplete="off">
                        <p v-if="err(i, 'lot_number')" class="error-text">{{ err(i, 'lot_number') }}</p>
                    </div>
                    <div v-if="asksExpiry(e)">
                        <label :for="`exp-${e.key}`" class="label">Expiry{{ e.line.expiry_policy === 'tracked_required' ? '' : ' (optional)' }}</label>
                        <input :id="`exp-${e.key}`" v-model="e.expiry" type="date" :class="['input', { 'input-invalid': err(i, 'expiry_date') }]">
                        <p v-if="err(i, 'expiry_date')" class="error-text">{{ err(i, 'expiry_date') }}</p>
                    </div>
                    <div v-if="e.line.tracking_mode === 'lot' || asksExpiry(e)">
                        <label :for="`mfg-${e.key}`" class="label">Manufactured (optional)</label>
                        <input :id="`mfg-${e.key}`" v-model="e.manufactured" type="date" class="input">
                    </div>
                </div>

                <div v-if="e.line.tracking_mode === 'serial'" class="mt-3">
                    <label :for="`ser-${e.key}`" class="label">Serial numbers — one per line ({{ serialList(e).length }} entered)</label>
                    <textarea :id="`ser-${e.key}`" v-model="e.serials" rows="3" :class="['input', { 'input-invalid': err(i, 'serial_numbers') }]" />
                    <p v-if="err(i, 'serial_numbers')" class="error-text">{{ err(i, 'serial_numbers') }}</p>
                </div>

                <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label :for="`cond-${e.key}`" class="label">Condition</label>
                        <EnumSelect :id="`cond-${e.key}`" enum-code="receiving_condition" :model-value="e.condition" @update:model-value="e.condition = ($event as string) ?? 'good'" />
                    </div>
                    <div>
                        <label :for="`rej-${e.key}`" class="label">Rejected (of received)</label>
                        <input :id="`rej-${e.key}`" v-model="e.rejected" class="input text-right tabular-nums" inputmode="decimal">
                    </div>
                    <div v-if="Number(e.rejected) > 0">
                        <label :for="`why-${e.key}`" class="label">Why rejected</label>
                        <select :id="`why-${e.key}`" v-model="e.reason" class="input">
                            <option :value="null">Choose…</option>
                            <option v-for="r in reasons" :key="r.id" :value="r.id">{{ r.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label :for="`loc-${e.key}`" class="label">Put into <strong class="font-semibold">{{ e.locationPath ?? 'the receiving area' }}</strong></label>
                        <BarcodeInput :id="`loc-${e.key}`" v-model="e.locationCode" placeholder="Scan a bin to change" @scan="scanBin(e, $event)" />
                        <ReferencePicker
                            v-if="can('organization.storage_location.read')"
                            :id="`locpick-${e.key}`"
                            v-model="e.location"
                            class="mt-2"
                            resource="storage-locations"
                            :filters="{ warehouse_id: order.warehouse_id }"
                            @picked="e.locationPath = $event?.full_path ?? null"
                        />
                    </div>
                </div>

                <!-- Quality checklist -->
                <fieldset v-if="needsQc(e)" class="mt-4 rounded-brand border border-line p-3">
                    <legend class="px-1 text-sm font-semibold">Quality check — without a passing check the stock goes to quarantine</legend>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div v-for="c in e.line.qc_checks" :key="c.code">
                            <label :for="`qc-${e.key}-${c.code}`" class="label">{{ c.label }}<span v-if="c.required !== false" class="text-danger"> *</span></label>
                            <select v-if="c.type === 'boolean'" :id="`qc-${e.key}-${c.code}`" v-model="e.answers[c.code]" class="input">
                                <option value="">Choose…</option>
                                <option value="true">Yes</option>
                                <option value="false">No</option>
                            </select>
                            <select v-else-if="c.type === 'choice'" :id="`qc-${e.key}-${c.code}`" v-model="e.answers[c.code]" class="input">
                                <option value="">Choose…</option>
                                <option v-for="o in c.choices ?? []" :key="o" :value="o">{{ o }}</option>
                            </select>
                            <input v-else-if="c.type === 'numeric'" :id="`qc-${e.key}-${c.code}`" v-model="e.answers[c.code]" class="input" inputmode="decimal"
                                   :placeholder="c.min || c.max ? `${c.min ?? ''} – ${c.max ?? ''}` : ''">
                            <input v-else :id="`qc-${e.key}-${c.code}`" v-model="e.answers[c.code]" class="input">
                        </div>
                        <div>
                            <label :for="`failed-${e.key}`" class="label">Quantity failing inspection</label>
                            <input :id="`failed-${e.key}`" v-model="e.failed" class="input text-right tabular-nums" inputmode="decimal">
                        </div>
                    </div>
                </fieldset>
            </li>
        </ul>

        <!-- 4. Review & finish -->
        <section v-if="entries.length" id="receive-finish" tabindex="-1" class="card mt-4">
            <h2 class="mb-2 font-semibold"><span class="mr-2 text-ink-muted">3</span>Review and finish</h2>
            <ul class="mb-4 divide-y divide-line text-sm">
                <li v-for="e in entries" :key="e.key" class="flex flex-wrap justify-between gap-2 py-2">
                    <span>{{ e.line.sku }}<template v-if="e.lot"> · lot {{ e.lot }}</template><template v-if="e.expiry"> · exp. {{ formatDate(e.expiry) }}</template></span>
                    <span class="tabular-nums">{{ e.line.tracking_mode === 'serial' ? serialList(e).length : formatQuantity(e.received || '0') }} {{ e.line.uom ?? '' }}<template v-if="Number(e.rejected) > 0"> ({{ formatQuantity(e.rejected) }} rejected)</template></span>
                </li>
            </ul>
            <div class="flex flex-wrap justify-end gap-2">
                <UiButton :loading="saving && !posting.busy.value" :disabled="saving" @click="finish(false)">{{ receiptId ? 'Keep as draft' : 'Save as draft' }}</UiButton>
                <UiButton variant="primary" :loading="saving" :disabled="saving" @click="finish(true)">
                    {{ saving ? (posting.busy.value ? 'Posting…' : 'Working…') : can('receiving.goods_receipt.post') ? 'Finish and post' : 'Finish and submit' }}
                </UiButton>
            </div>
            <p v-if="receiptId" class="help mt-2">Saved as <Link :href="`/r/goods-receipts/${receiptId}`" class="underline">this receipt</Link>; finishing again continues it.</p>
        </section>
    </template>
</template>
