<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import { api, download, get, post } from '@/lib/api';
import { formatDate, formatDateTime, formatMoney } from '@/lib/format';
import { notify } from '@/lib/toast';

/** A payment confirmation: its review status, finance's note, the private evidence, and adding information when asked. */
const props = defineProps<{ paymentId: string }>();
const c = ref<Record<string, any> | null>(null);
const error = ref<unknown>(null);
const note = ref('');
const files = ref<File[]>([]);
const busy = ref(false);

const MESSAGES: Record<string, string> = {
    submitted: 'Our finance team is checking that the money reached our account. The invoice balance changes once it is verified.',
    info_requested: 'We need more information before we can verify this payment.',
    verified: 'Verified: the payment is recorded on your account.',
    rejected: 'We could not verify this payment.',
    withdrawn: 'You withdrew this confirmation.',
};

async function load() {
    try {
        c.value = (await get(`/api/v1/portal/payments/${props.paymentId}`)).data;
    } catch (e) {
        error.value = e;
    }
}
onMounted(load);

async function addInformation() {
    busy.value = true;
    error.value = null;
    try {
        const data = new FormData();
        if (note.value) {
            data.append('note', note.value);
        }
        files.value.forEach((f) => data.append('evidence[]', f));
        await api(`/api/v1/portal/payments/${props.paymentId}/information`, { method: 'POST', form: data });
        note.value = '';
        files.value = [];
        notify('Information sent.');
        await load();
    } catch (e) {
        error.value = e;
    } finally {
        busy.value = false;
    }
}

async function withdraw() {
    try {
        await post(`/api/v1/portal/payments/${props.paymentId}/withdraw`);
        await load();
    } catch (e) {
        error.value = e;
    }
}

async function open(file: { id: string; filename: string }) {
    try {
        await download(`/api/v1/portal/payments/${props.paymentId}/evidence/${file.id}`, file.filename);
    } catch (e) {
        error.value = e;
    }
}
</script>

<template>
    <Head :title="c?.document_number ?? 'Payment'" />
    <PageHeader :title="c?.document_number ?? 'Payment confirmation'" subtitle="Payment confirmation" back="/portal/payments" />
    <ErrorSummary :error="error" />
    <div v-if="c" class="grid gap-6 lg:grid-cols-[2fr_1fr]">
        <div class="space-y-6">
            <section class="card space-y-3">
                <div class="flex flex-wrap items-center gap-3">
                    <StatusBadge :value="c.status" :label="c.status === 'submitted' ? 'Under review' : c.status_label" />
                    <span class="ml-auto text-xl font-bold">{{ formatMoney(c.amount, c.currency) }}</span>
                </div>
                <p class="text-sm">{{ MESSAGES[c.status] }}</p>
                <p v-if="c.review_note" class="rounded-brand border border-line bg-surface-muted p-3 text-sm"><strong>From finance:</strong> {{ c.review_note }}</p>
                <p v-if="c.payment_number" class="text-sm">Recorded as payment <strong>{{ c.payment_number }}</strong>.</p>
                <dl class="grid gap-2 text-sm sm:grid-cols-2">
                    <div><dt class="text-ink-muted">Paid on</dt><dd>{{ formatDate(c.paid_on) }}</dd></div>
                    <div><dt class="text-ink-muted">Method</dt><dd>{{ c.method_label }}</dd></div>
                    <div><dt class="text-ink-muted">Reference</dt><dd class="font-mono">{{ c.transaction_reference }}</dd></div>
                    <div v-if="c.bank_name"><dt class="text-ink-muted">Bank / wallet</dt><dd>{{ c.bank_name }}</dd></div>
                    <div v-if="c.payer_name"><dt class="text-ink-muted">Paid by</dt><dd>{{ c.payer_name }}</dd></div>
                    <div v-if="c.invoice"><dt class="text-ink-muted">Invoice</dt><dd><Link :href="`/portal/invoices/${c.invoice.id}`" class="underline">{{ c.invoice.document_number }}</Link></dd></div>
                    <div v-else-if="c.order"><dt class="text-ink-muted">Order</dt><dd><Link :href="`/portal/orders/${c.order.id}`" class="underline">{{ c.order.document_number }}</Link></dd></div>
                </dl>
                <p v-if="c.customer_note" class="whitespace-pre-line text-sm text-ink-muted">{{ c.customer_note }}</p>
                <UiButton v-if="['submitted', 'info_requested'].includes(c.status)" variant="ghost" @click="withdraw">Withdraw this confirmation</UiButton>
            </section>

            <form v-if="c.status === 'info_requested'" class="card space-y-4" @submit.prevent="addInformation">
                <h2 class="text-lg font-semibold">Add information</h2>
                <div>
                    <label for="pi-note" class="label">Message</label>
                    <textarea id="pi-note" v-model="note" class="input min-h-20" />
                </div>
                <div>
                    <label for="pi-files" class="label">More files</label>
                    <input id="pi-files" type="file" multiple accept="image/jpeg,image/png,image/webp,application/pdf" class="block w-full text-sm"
                           @change="files = Array.from(($event.target as HTMLInputElement).files ?? []).slice(0, 5)">
                </div>
                <UiButton variant="primary" type="submit" :loading="busy" :disabled="!note && !files.length">Send</UiButton>
            </form>
        </div>
        <section class="card h-fit">
            <h2 class="mb-2 text-lg font-semibold">Evidence</h2>
            <ul class="space-y-2 text-sm">
                <li v-for="f in c.evidence" :key="f.id" class="flex items-center gap-2">
                    <button type="button" class="min-h-11 flex-1 truncate text-left underline" @click="open(f)">{{ f.filename }}</button>
                    <span class="text-xs text-ink-muted">{{ Math.ceil(f.size / 1024) }} KB · {{ formatDateTime(f.uploaded_at) }}</span>
                </li>
            </ul>
            <p class="mt-3 text-xs text-ink-muted">Only your company's members and our staff can open these files.</p>
        </section>
    </div>
</template>
