<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import PaymentConfirmForm from '@/components/portal/PaymentConfirmForm.vue';
import { download, get } from '@/lib/api';
import { formatDate, formatMoney, formatQuantity } from '@/lib/format';
import { notify } from '@/lib/toast';
import type { NextAction } from '@/types/shared';

/** One invoice: totals and balance from the billing ledger, verified payments, payment confirmations and the PDF. */
const props = defineProps<{ invoiceId: string }>();
const invoice = ref<Record<string, any> | null>(null);
const error = ref<unknown>(null);
const paying = ref(false);
const nextActions = computed<NextAction[]>(() => {
    const i = invoice.value;
    if (!i) {
        return [];
    }

    return i.can_confirm_payment
        ? [{ label: 'I have paid — upload my bank slip', icon: 'coins', onClick: () => (paying.value = true), hint: 'We match the payment to this invoice once we receive it.' }]
        : [{ label: 'All invoices', icon: 'file', href: '/portal/invoices', hint: Number(i.balance_due) > 0 ? undefined : 'This invoice is paid.' }];
});

async function load() {
    try {
        invoice.value = (await get(`/api/v1/portal/invoices/${props.invoiceId}`)).data;
    } catch (e) {
        error.value = e;
    }
}
onMounted(load);

async function pdf() {
    try {
        await download(`/api/v1/portal/invoices/${props.invoiceId}/document`, `${invoice.value?.document_number}.pdf`);
    } catch (e) {
        error.value = e;
    }
}

function submitted(id: string) {
    notify('Payment confirmation sent. We will let you know once finance has verified it.');
    router.visit(`/portal/payments/${id}`);
}
</script>

<template>
    <Head :title="invoice?.document_number ?? 'Invoice'" />
    <PageHeader :title="invoice?.document_number ?? 'Invoice'" subtitle="Invoice" back="/portal/invoices" :next="nextActions">
        <template #actions><UiButton v-if="invoice" icon="download" @click="pdf">PDF</UiButton></template>
    </PageHeader>
    <ErrorSummary :error="error" />
    <div v-if="invoice" class="grid gap-6 lg:grid-cols-[2fr_1fr]">
        <div class="space-y-6">
            <section class="card overflow-x-auto">
                <div class="mb-4 flex flex-wrap items-center gap-3">
                    <StatusBadge :value="invoice.overdue ? 'overdue' : invoice.status" :label="invoice.overdue ? 'Overdue' : invoice.status_label" />
                    <span class="text-sm text-ink-muted">Issued {{ formatDate(invoice.issue_date) }} · due {{ formatDate(invoice.due_date) }}</span>
                    <Link v-if="invoice.order" :href="`/portal/orders/${invoice.order.id}`" class="text-sm underline">Order {{ invoice.order.document_number }}</Link>
                </div>
                <table class="w-full text-sm">
                    <thead><tr class="border-b border-line text-left text-ink-muted"><th class="py-2">Description</th><th class="py-2 text-right">Qty</th><th class="py-2 text-right">Price</th><th class="py-2 text-right">Tax</th><th class="py-2 text-right">Total</th></tr></thead>
                    <tbody>
                        <tr v-for="l in invoice.lines" :key="l.line_no" class="border-b border-line">
                            <td class="py-2">{{ l.description }}</td>
                            <td class="py-2 text-right">{{ formatQuantity(l.quantity) }}</td>
                            <td class="py-2 text-right">{{ formatMoney(l.unit_price, invoice.currency) }}</td>
                            <td class="py-2 text-right">{{ Number(l.tax_rate) }}%</td>
                            <td class="py-2 text-right">{{ formatMoney(l.line_total, invoice.currency) }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="text-right">
                        <tr><td colspan="4" class="pt-3">Subtotal</td><td class="pt-3">{{ formatMoney(invoice.subtotal, invoice.currency) }}</td></tr>
                        <tr><td colspan="4">Tax</td><td>{{ formatMoney(invoice.tax_amount, invoice.currency) }}</td></tr>
                        <tr class="font-semibold"><td colspan="4">Total</td><td>{{ formatMoney(invoice.total, invoice.currency) }}</td></tr>
                        <tr><td colspan="4">Paid</td><td>{{ formatMoney(invoice.amount_paid, invoice.currency) }}</td></tr>
                        <tr class="text-lg font-bold"><td colspan="4">Balance due</td><td>{{ formatMoney(invoice.balance_due, invoice.currency) }}</td></tr>
                    </tfoot>
                </table>
            </section>

            <section v-if="invoice.can_confirm_payment" class="card">
                <h2 class="mb-3 text-lg font-semibold">Confirm a payment</h2>
                <UiButton v-if="!paying" variant="primary" icon="coins" @click="paying = true">I have paid — upload my bank slip</UiButton>
                <PaymentConfirmForm v-else :invoice-id="invoice.id" :currency="invoice.currency" :outstanding="invoice.balance_due" @submitted="submitted" />
            </section>
        </div>

        <div class="space-y-6">
            <section class="card">
                <h2 class="mb-2 text-lg font-semibold">Verified payments</h2>
                <p v-if="!invoice.payments.length" class="text-sm text-ink-muted">None yet.</p>
                <ul class="space-y-2 text-sm">
                    <li v-for="p in invoice.payments" :key="p.document_number" class="flex justify-between gap-2">
                        <span>{{ p.document_number }} · {{ formatDate(p.received_on) }}</span>
                        <span class="font-semibold">{{ formatMoney(p.amount, invoice.currency) }}</span>
                    </li>
                </ul>
            </section>
            <section v-if="invoice.confirmations.length" class="card">
                <h2 class="mb-2 text-lg font-semibold">Your payment confirmations</h2>
                <ul class="divide-y divide-line text-sm">
                    <li v-for="c in invoice.confirmations" :key="c.id">
                        <Link :href="`/portal/payments/${c.id}`" class="flex items-center gap-2 py-2 hover:bg-surface-muted">
                            <span class="flex-1">{{ c.document_number }} · {{ formatMoney(c.amount, c.currency) }}</span>
                            <StatusBadge :value="c.status" :label="c.status_label" />
                        </Link>
                    </li>
                </ul>
            </section>
            <section v-if="invoice.notes" class="card">
                <h2 class="mb-2 text-lg font-semibold">Notes</h2>
                <p class="whitespace-pre-line text-sm">{{ invoice.notes }}</p>
            </section>
        </div>
    </div>
</template>
