<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import { get } from '@/lib/api';
import { formatDate, formatMoney } from '@/lib/format';
import type { NextAction } from '@/types/shared';

/** The payment confirmations this customer sent, and what finance decided. */
const rows = ref<Array<Record<string, any>>>([]);
const loaded = ref(false);
const error = ref<unknown>(null);
const nextActions = computed<NextAction[]>(() => {
    const asked = rows.value.find((c) => c.status === 'info_requested');

    return asked
        ? [{ label: `Answer ${asked.document_number}`, icon: 'info', href: `/portal/payments/${asked.id}`, hint: 'We need a little more information to confirm this payment.' }]
        : loaded.value ? [{ label: 'Pay an open invoice', icon: 'file', href: '/portal/invoices', hint: 'Payments you confirm are reviewed by our team.' }] : [];
});

onMounted(async () => {
    try {
        rows.value = (await get('/api/v1/portal/payments')).data;
    } catch (e) {
        error.value = e;
    } finally {
        loaded.value = true;
    }
});
</script>

<template>
    <Head title="Payments" />
    <PageHeader title="Payment confirmations" :next="nextActions" />
    <ErrorSummary :error="error" />
    <p class="mb-3 text-sm text-ink-muted">To confirm a new payment, open the <Link href="/portal/invoices" class="underline">invoice</Link> you paid.</p>
    <div class="card">
        <p v-if="loaded && !rows.length" class="py-8 text-center text-ink-muted">You have not sent any payment confirmations.</p>
        <ul class="divide-y divide-line">
            <li v-for="c in rows" :key="c.id">
                <Link :href="`/portal/payments/${c.id}`" class="flex flex-wrap items-center gap-3 py-3 hover:bg-surface-muted">
                    <span class="min-w-0 flex-1">
                        <span class="block font-medium">{{ c.document_number }} · {{ c.transaction_reference }}</span>
                        <span class="block text-sm text-ink-muted">
                            Paid {{ formatDate(c.paid_on) }} · {{ c.method_label }}<template v-if="c.invoice"> · invoice {{ c.invoice.document_number }}</template>
                            <template v-else-if="c.order"> · order {{ c.order.document_number }}</template>
                        </span>
                    </span>
                    <span class="font-semibold">{{ formatMoney(c.amount, c.currency) }}</span>
                    <StatusBadge :value="c.status" :label="c.status === 'submitted' ? 'Under review' : c.status_label" />
                </Link>
            </li>
        </ul>
    </div>
</template>
