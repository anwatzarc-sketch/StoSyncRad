<script setup lang="ts">
import { ref } from 'vue';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import { post } from '@/lib/api';

/**
 * Confirm what arrived (CP-1.1 section 5.7). Recorded with your name and the server time; reported
 * issues go to our team, who follow up through returns or replacements. Nothing here changes stock.
 */
const props = defineProps<{ orderId: string; shipmentId?: string | null; lines: Array<{ id: string; name: string; sku: string; unit: string }> }>();
const emit = defineEmits<{ done: [] }>();

const outcome = ref<'received_in_full' | 'received_with_issues' | 'not_received'>('received_in_full');
const note = ref('');
const issues = ref<Array<{ sales_order_line_id: string; issue: string; quantity: string; note: string }>>([]);
const busy = ref(false);
const error = ref<unknown>(null);

function addIssue() {
    issues.value.push({ sales_order_line_id: props.lines[0]?.id ?? '', issue: 'damaged', quantity: '', note: '' });
}

async function submit() {
    busy.value = true;
    error.value = null;
    try {
        const url = props.shipmentId ? `/api/v1/portal/orders/${props.orderId}/shipments/${props.shipmentId}/acknowledge` : `/api/v1/portal/orders/${props.orderId}/acknowledge`;
        await post(url, { outcome: outcome.value, note: note.value || null,
            issues: outcome.value === 'received_in_full' ? [] : issues.value.map((i) => ({ ...i, quantity: i.quantity || null, note: i.note || null })) });
        emit('done');
    } catch (e) {
        error.value = e;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <form class="space-y-4" @submit.prevent="submit">
        <ErrorSummary :error="error" />
        <fieldset class="space-y-2">
            <legend class="label">What arrived?</legend>
            <label class="flex min-h-11 items-center gap-2"><input v-model="outcome" type="radio" value="received_in_full" class="size-5 accent-brand"> Everything, in good condition</label>
            <label class="flex min-h-11 items-center gap-2"><input v-model="outcome" type="radio" value="received_with_issues" class="size-5 accent-brand" @change="issues.length || addIssue()"> Received, with problems</label>
            <label class="flex min-h-11 items-center gap-2"><input v-model="outcome" type="radio" value="not_received" class="size-5 accent-brand"> Nothing arrived</label>
        </fieldset>
        <div v-if="outcome !== 'received_in_full'" class="space-y-3">
            <div v-for="(issue, i) in issues" :key="i" class="grid gap-2 rounded-brand border border-line p-3 sm:grid-cols-[2fr_1fr_1fr]">
                <select v-model="issue.sales_order_line_id" class="input" :aria-label="`Product for issue ${i + 1}`">
                    <option v-for="l in lines" :key="l.id" :value="l.id">{{ l.name }} ({{ l.sku }})</option>
                </select>
                <select v-model="issue.issue" class="input" :aria-label="`Problem for issue ${i + 1}`">
                    <option value="shortage">Short / missing</option>
                    <option value="damaged">Damaged</option>
                    <option value="wrong_item">Wrong item</option>
                    <option value="other">Other</option>
                </select>
                <input v-model="issue.quantity" class="input" inputmode="decimal" placeholder="Quantity" :aria-label="`Quantity for issue ${i + 1}`">
                <input v-model="issue.note" class="input sm:col-span-3" placeholder="Details" :aria-label="`Details for issue ${i + 1}`">
            </div>
            <UiButton icon="plus" @click="addIssue">Add a problem</UiButton>
        </div>
        <div>
            <label for="d-note" class="label">Note</label>
            <textarea id="d-note" v-model="note" class="input min-h-16" />
        </div>
        <UiButton variant="primary" type="submit" :loading="busy">Confirm</UiButton>
    </form>
</template>
