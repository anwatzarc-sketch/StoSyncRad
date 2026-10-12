<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import ModalDialog from '@/components/ui/ModalDialog.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import { download, get, post } from '@/lib/api';
import { formatDate, formatMoney, formatQuantity } from '@/lib/format';
import { notify } from '@/lib/toast';

/** A quotation: lines and prices once staff priced it; accept (creates the order once), decline or ask for changes. */
const props = defineProps<{ quotationId: string }>();
const q = ref<Record<string, any> | null>(null);
const error = ref<unknown>(null);
const busy = ref(false);
const dialog = ref<'decline' | 'revise' | null>(null);
const reason = ref('');

async function load() {
    try {
        q.value = (await get(`/api/v1/portal/quotations/${props.quotationId}`)).data;
    } catch (e) {
        error.value = e;
    }
}
onMounted(load);

async function accept() {
    busy.value = true;
    error.value = null;
    try {
        const { data } = await post(`/api/v1/portal/quotations/${props.quotationId}/accept`);
        notify(`Accepted. Order ${data.order.document_number} is created.`);
        router.visit(`/portal/orders/${data.order.id}`);
    } catch (e) {
        error.value = e;
        await load();
    } finally {
        busy.value = false;
    }
}

async function respond() {
    busy.value = true;
    try {
        await post(`/api/v1/portal/quotations/${props.quotationId}/${dialog.value}`, { reason: reason.value || null });
        notify(dialog.value === 'revise' ? 'We will send you a revised quotation.' : 'Quotation declined.');
        dialog.value = null;
        reason.value = '';
        await load();
    } catch (e) {
        error.value = e;
    } finally {
        busy.value = false;
    }
}

async function pdf() {
    try {
        await download(`/api/v1/portal/quotations/${props.quotationId}/document`, `${q.value?.document_number}.pdf`);
    } catch (e) {
        error.value = e;
    }
}
</script>

<template>
    <Head :title="q?.document_number ?? 'Quotation'" />
    <PageHeader :title="q?.document_number ?? 'Quotation'" subtitle="Quotation" back="/portal/quotations">
        <template #actions>
            <UiButton v-if="q && q.total !== null && q.status !== 'cancelled'" icon="download" @click="pdf">PDF</UiButton>
        </template>
    </PageHeader>
    <ErrorSummary :error="error" />
    <div v-if="q" class="space-y-6">
        <section class="card">
            <div class="flex flex-wrap items-center gap-3">
                <StatusBadge :value="q.status" :label="q.status_label" />
                <span v-if="q.valid_until" class="text-sm text-ink-muted">Valid until {{ formatDate(q.valid_until) }}</span>
                <span v-if="q.revision > 1" class="text-sm text-ink-muted">Revision {{ q.revision }}</span>
                <span v-if="q.total" class="ml-auto text-xl font-bold">{{ formatMoney(q.total, q.currency) }}</span>
            </div>
            <p v-if="q.status === 'requested' || q.status === 'revision_requested'" class="mt-3 text-sm text-ink-muted">We are preparing your prices. You will be notified when the quotation is ready.</p>
            <p v-if="q.staff_note" class="mt-3 whitespace-pre-line text-sm"><strong>From us:</strong> {{ q.staff_note }}</p>
            <p v-if="q.revision_note" class="mt-2 whitespace-pre-line text-sm text-ink-muted"><strong>Your note:</strong> {{ q.revision_note }}</p>
            <p v-if="q.order" class="mt-3 text-sm">Order: <Link :href="`/portal/orders/${q.order.id}`" class="font-semibold underline">{{ q.order.document_number }}</Link></p>
            <div v-if="q.can_respond" class="mt-4 flex flex-wrap gap-2">
                <UiButton variant="primary" :loading="busy" @click="accept">Accept and order</UiButton>
                <UiButton @click="dialog = 'revise'">Ask for changes</UiButton>
                <UiButton variant="ghost" @click="dialog = 'decline'">Decline</UiButton>
            </div>
        </section>
        <section class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="border-b border-line text-left text-ink-muted"><th class="py-2">Product</th><th class="py-2 text-right">Quantity</th><th class="py-2 text-right">Unit price</th><th class="py-2 text-right">Total</th></tr></thead>
                <tbody>
                    <tr v-for="l in q.lines" :key="l.id" class="border-b border-line last:border-0">
                        <td class="py-2"><span class="font-medium">{{ l.name }}</span><br><span class="text-xs text-ink-muted">{{ l.sku }}</span></td>
                        <td class="py-2 text-right">{{ formatQuantity(l.quantity) }} {{ l.unit }}</td>
                        <td class="py-2 text-right">{{ l.unit_price !== null ? formatMoney(l.unit_price, q.currency) : '—' }}</td>
                        <td class="py-2 text-right">{{ l.line_total !== null ? formatMoney(l.line_total, q.currency) : '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>

    <ModalDialog :open="dialog !== null" :title="dialog === 'revise' ? 'Ask for changes' : 'Decline the quotation'" @close="dialog = null">
        <form class="space-y-4" @submit.prevent="respond">
            <div>
                <label for="q-reason" class="label">{{ dialog === 'revise' ? 'What should we change?' : 'Reason (optional)' }}</label>
                <textarea id="q-reason" v-model="reason" class="input min-h-24" :required="dialog === 'revise'" />
            </div>
            <div class="flex justify-end gap-2">
                <UiButton @click="dialog = null">Back</UiButton>
                <UiButton variant="primary" type="submit" :loading="busy">{{ dialog === 'revise' ? 'Send request' : 'Decline' }}</UiButton>
            </div>
        </form>
    </ModalDialog>
</template>
