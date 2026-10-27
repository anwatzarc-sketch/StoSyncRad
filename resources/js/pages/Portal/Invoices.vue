<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import { get } from '@/lib/api';
import { formatDate, formatMoney } from '@/lib/format';
import type { NextAction } from '@/types/shared';

const rows = ref<Array<Record<string, any>>>([]);
const loaded = ref(false);
const error = ref<unknown>(null);
const nextActions = computed<NextAction[]>(() => {
    const unpaid = rows.value.find((r) => ['issued', 'partially_paid'].includes(r.status));

    return unpaid
        ? [{ label: `Pay ${unpaid.document_number}`, icon: 'coins', href: `/portal/invoices/${unpaid.id}`, hint: 'After paying, upload the bank slip so we can match it.' }]
        : loaded.value ? [{ label: 'See my payments', icon: 'coins', href: '/portal/payments', hint: 'Nothing is due.' }] : [];
});
const filter = ref<'open' | 'all'>('open');
const shown = computed(() => (filter.value === 'open' ? rows.value.filter((r) => ['issued', 'partially_paid'].includes(r.status)) : rows.value));

onMounted(async () => {
    try {
        rows.value = (await get('/api/v1/portal/invoices')).data;
    } catch (e) {
        error.value = e;
    } finally {
        loaded.value = true;
    }
});
</script>

<template>
    <Head title="Invoices" />
    <PageHeader title="Invoices" :next="nextActions" />
    <ErrorSummary :error="error" />
    <div class="mb-3 flex gap-2" role="tablist" aria-label="Invoices">
        <button type="button" role="tab" :aria-selected="filter === 'open'" :class="['btn', filter === 'open' ? 'btn-primary' : 'btn-secondary']" @click="filter = 'open'">To pay</button>
        <button type="button" role="tab" :aria-selected="filter === 'all'" :class="['btn', filter === 'all' ? 'btn-primary' : 'btn-secondary']" @click="filter = 'all'">All</button>
    </div>
    <div class="card">
        <p v-if="loaded && !shown.length" class="py-8 text-center text-ink-muted">{{ filter === 'open' ? 'Nothing to pay. Thank you!' : 'No invoices yet.' }}</p>
        <ul class="divide-y divide-line">
            <li v-for="i in shown" :key="i.id">
                <Link :href="`/portal/invoices/${i.id}`" class="flex flex-wrap items-center gap-3 py-3 hover:bg-surface-muted">
                    <span class="min-w-0 flex-1">
                        <span class="block font-medium">{{ i.document_number }}</span>
                        <span class="block text-sm text-ink-muted">Issued {{ formatDate(i.issue_date) }} · due {{ formatDate(i.due_date) }}</span>
                    </span>
                    <span class="text-right">
                        <span class="block font-semibold">{{ formatMoney(i.balance_due, i.currency) }}</span>
                        <span class="block text-xs text-ink-muted">of {{ formatMoney(i.total, i.currency) }}</span>
                    </span>
                    <StatusBadge :value="i.overdue ? 'overdue' : i.status" :label="i.overdue ? 'Overdue' : i.status_label" />
                </Link>
            </li>
        </ul>
    </div>
</template>
