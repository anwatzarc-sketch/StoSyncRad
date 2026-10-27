<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import ModalDialog from '@/components/ui/ModalDialog.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import DeliveryReceiptForm from '@/components/portal/DeliveryReceiptForm.vue';
import PaymentConfirmForm from '@/components/portal/PaymentConfirmForm.vue';
import { get, post } from '@/lib/api';
import { formatDate, formatDateTime, formatMoney, formatQuantity } from '@/lib/format';
import { notify } from '@/lib/toast';
import type { NextAction } from '@/types/shared';

/** One order: what was agreed, the recorded progress (timeline), shipments with tracking, receipt confirmation and invoices. */
const props = defineProps<{ orderId: string }>();
const order = ref<Record<string, any> | null>(null);
const error = ref<unknown>(null);
const receiptFor = ref<{ shipmentId: string | null } | null>(null);
const paying = ref(false);
const cancelling = ref(false);
const reason = ref('');
/** Next action: confirm what arrived, pay what is due, or just follow the order. */
const nextActions = computed<NextAction[]>(() => {
    const o = order.value;
    if (!o) {
        return [];
    }
    const arrived = o.shipments.find((s: any) => !s.acknowledged && ['handed_over', 'in_transit', 'delivered'].includes(s.status));
    if (arrived || o.can_acknowledge_direct) {
        return [{ label: 'Confirm what you received', icon: 'check', onClick: () => (receiptFor.value = { shipmentId: arrived?.id ?? null }), hint: 'Tell us if anything is missing or damaged.' }];
    }
    const due = o.invoices.find((i: any) => Number(i.balance_due) > 0);
    if (due) {
        return [{ label: `Pay invoice ${due.document_number}`, icon: 'coins', href: `/portal/invoices/${due.id}` }];
    }

    return [{ label: 'All orders', icon: 'truck', href: '/portal/orders', hint: o.status === 'draft' ? 'We confirm your order shortly.' : undefined }];
});

const receiptLines = computed(() => (order.value?.lines ?? []).map((l: any) => ({ id: l.id, name: l.name, sku: l.sku, unit: l.unit })));
async function load() {
    try {
        order.value = (await get(`/api/v1/portal/orders/${props.orderId}`)).data;
    } catch (e) {
        error.value = e;
    }
}
onMounted(load);

async function cancel() {
    try {
        await post(`/api/v1/portal/orders/${props.orderId}/cancel`, { reason: reason.value || null });
        cancelling.value = false;
        notify('Order cancelled.');
        await load();
    } catch (e) {
        error.value = e;
        cancelling.value = false;
    }
}

function paymentSent(id: string) {
    notify('Payment confirmation sent. We will let you know once finance has verified it.');
    router.visit(`/portal/payments/${id}`);
}

function receiptDone() {
    receiptFor.value = null;
    notify('Thank you — your confirmation is recorded.');
    void load();
}
</script>

<template>
    <Head :title="order?.document_number ?? 'Order'" />
    <PageHeader :title="order?.document_number ?? 'Order'" subtitle="Order" back="/portal/orders" :next="nextActions" />
    <ErrorSummary :error="error" />
    <div v-if="order" class="grid gap-6 lg:grid-cols-[2fr_1fr]">
        <div class="space-y-6">
            <section class="card">
                <div class="flex flex-wrap items-center gap-3">
                    <StatusBadge :value="order.status" :label="order.status === 'draft' ? 'Awaiting confirmation' : order.status_label" />
                    <span class="text-sm text-ink-muted">{{ formatDate(order.document_date) }}</span>
                    <span class="ml-auto text-xl font-bold">{{ formatMoney(order.total, order.currency) }}</span>
                </div>
                <p v-if="order.reference" class="mt-2 text-sm">Your reference: {{ order.reference }}</p>
                <p v-if="order.from_quotation" class="mt-1 text-sm text-ink-muted">From quotation {{ order.from_quotation }}</p>
                <div class="mt-4 flex flex-wrap gap-2">
                    <UiButton v-if="order.status !== 'cancelled' && !order.invoices.length" icon="coins" @click="paying = true">Confirm an advance payment</UiButton>
                    <UiButton v-if="order.can_acknowledge_direct" variant="primary" @click="receiptFor = { shipmentId: null }">Confirm receipt</UiButton>
                    <UiButton v-if="order.can_cancel" variant="ghost" @click="cancelling = true">Cancel order</UiButton>
                </div>
            </section>

            <section class="card overflow-x-auto">
                <h2 class="mb-3 text-lg font-semibold">Products</h2>
                <table class="w-full text-sm">
                    <thead><tr class="border-b border-line text-left text-ink-muted"><th class="py-2">Product</th><th class="py-2 text-right">Ordered</th><th class="py-2 text-right">Delivered</th><th class="py-2 text-right">Total</th></tr></thead>
                    <tbody>
                        <tr v-for="l in order.lines" :key="l.id" class="border-b border-line last:border-0">
                            <td class="py-2"><span class="font-medium">{{ l.name }}</span><br><span class="text-xs text-ink-muted">{{ l.sku }}</span></td>
                            <td class="py-2 text-right">{{ formatQuantity(l.quantity) }} {{ l.unit }}</td>
                            <td class="py-2 text-right">{{ formatQuantity(l.delivered_base_quantity) }}</td>
                            <td class="py-2 text-right">{{ formatMoney(l.line_total, order.currency) }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section v-if="order.shipments.length" class="card space-y-3">
                <h2 class="text-lg font-semibold">Shipments</h2>
                <div v-for="s in order.shipments" :key="s.id" class="flex flex-wrap items-center gap-3 rounded-brand border border-line p-3">
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">{{ s.document_number }} <StatusBadge :value="s.status" class="ml-1" /></p>
                        <p class="text-sm text-ink-muted">
                            <template v-if="s.carrier_name">{{ s.carrier_name }} · </template>
                            <template v-if="s.tracking_number">Tracking {{ s.tracking_number }}</template>
                            <template v-if="s.delivered_at"> · delivered {{ formatDateTime(s.delivered_at) }}</template>
                        </p>
                    </div>
                    <a v-if="s.tracking_url" :href="s.tracking_url" target="_blank" rel="noopener" class="btn btn-ghost">Track</a>
                    <UiButton v-if="!s.acknowledged && ['handed_over', 'in_transit', 'delivered'].includes(s.status)" variant="primary" @click="receiptFor = { shipmentId: s.id }">
                        Confirm receipt
                    </UiButton>
                    <span v-else-if="s.acknowledged" class="text-sm text-success">Receipt confirmed</span>
                </div>
            </section>

            <section v-if="order.invoices.length" class="card">
                <h2 class="mb-3 text-lg font-semibold">Invoices</h2>
                <ul class="divide-y divide-line">
                    <li v-for="i in order.invoices" :key="i.id">
                        <Link :href="`/portal/invoices/${i.id}`" class="flex items-center gap-3 py-2 hover:bg-surface-muted">
                            <span class="flex-1 font-medium">{{ i.document_number }}</span>
                            <span>{{ formatMoney(i.balance_due, i.currency) }} due</span>
                            <StatusBadge :value="i.status" />
                        </Link>
                    </li>
                </ul>
            </section>
        </div>

        <section class="card h-fit">
            <h2 class="mb-3 text-lg font-semibold">Progress</h2>
            <ol class="relative space-y-4 border-l border-line pl-6">
                <li v-for="(e, i) in order.timeline" :key="i" class="relative">
                    <span class="absolute -left-[33px] grid size-6 place-items-center rounded-full bg-accent text-accent-contrast" aria-hidden="true">
                        <span class="text-[10px]">●</span>
                    </span>
                    <p class="font-medium">{{ e.label }}</p>
                    <p class="text-xs text-ink-muted">{{ formatDateTime(e.at) }}<template v-if="e.detail"> · {{ e.detail }}</template></p>
                </li>
            </ol>
            <p class="mt-4 text-xs text-ink-muted">Only steps we have recorded are shown.</p>
        </section>
    </div>

    <ModalDialog :open="receiptFor !== null" title="Confirm receipt" wide @close="receiptFor = null">
        <DeliveryReceiptForm v-if="receiptFor && order" :order-id="order.id" :shipment-id="receiptFor.shipmentId" :lines="receiptLines" @done="receiptDone" />
    </ModalDialog>
    <ModalDialog :open="paying" title="Confirm an advance payment" wide @close="paying = false">
        <PaymentConfirmForm v-if="paying && order" :order-id="order.id" :currency="order.currency ?? ''" :outstanding="order.total"
                            @submitted="paymentSent" />
    </ModalDialog>
    <ModalDialog :open="cancelling" title="Cancel this order?" @close="cancelling = false">
        <form class="space-y-4" @submit.prevent="cancel">
            <div>
                <label for="o-reason" class="label">Reason (optional)</label>
                <textarea id="o-reason" v-model="reason" class="input min-h-20" />
            </div>
            <div class="flex justify-end gap-2">
                <UiButton @click="cancelling = false">Keep the order</UiButton>
                <UiButton variant="danger" type="submit">Cancel order</UiButton>
            </div>
        </form>
    </ModalDialog>
</template>
