<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import AppIcon from '@/components/ui/AppIcon.vue';
import BarcodeInput from '@/components/BarcodeInput.vue';
import { get } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { notify } from '@/lib/toast';

/**
 * Receive goods, step 1 (Chapter 08 §8.3): pick the purchase order that is arriving — scan or type
 * its number, or choose from the orders awaiting delivery in your warehouses (the work queue
 * `purchase-orders.receive`, scoped by the permission to receive).
 */
const orders = ref<Record<string, any>[]>([]);
const cursor = ref<string | null>(null);
const loading = ref(true);
const error = ref<unknown>(null);
const code = ref('');
const search = ref('');

const shown = computed(() => {
    const term = search.value.trim().toLowerCase();

    return term ? orders.value.filter((o) => `${o.document_number} ${o.supplier_reference ?? ''} ${o.reference ?? ''}`.toLowerCase().includes(term)) : orders.value;
});

async function load(append = false) {
    loading.value = true;
    try {
        const r = await get('/api/v1/work/queues/purchase-orders.receive', { per_page: 100, cursor: append ? cursor.value : undefined });
        orders.value = append ? [...orders.value, ...r.data] : r.data;
        cursor.value = r.meta?.next_cursor ?? null;
    } catch (e) {
        error.value = e;
    } finally {
        loading.value = false;
    }
}

function scan(value: string) {
    const v = value.trim().toLowerCase();
    const match = orders.value.find((o) => String(o.document_number).toLowerCase() === v);
    code.value = '';
    if (match) {
        router.visit(`/tasks/receiving/${match.id}`);
    } else {
        search.value = value.trim();
        notify(`No order awaiting delivery matches “${value.trim()}”.`, 'error');
    }
}

onMounted(() => void load());
</script>

<template>
    <PageHeader title="Receive goods" subtitle="Choose the purchase order that is being delivered" back="/work/receiving" />
    <ErrorSummary :error="error" />

    <div class="card mb-4 grid gap-3 md:grid-cols-2">
        <div>
            <label for="po-scan" class="label">Scan the order number</label>
            <BarcodeInput id="po-scan" v-model="code" autofocus @scan="scan" />
        </div>
        <div>
            <label for="po-search" class="label">or search</label>
            <input id="po-search" v-model="search" type="search" class="input" placeholder="Order number or reference" autocomplete="off">
        </div>
    </div>

    <div v-if="loading && !orders.length" class="space-y-3" aria-busy="true">
        <div v-for="n in 3" :key="n" class="h-20 animate-pulse rounded-brand bg-surface" />
    </div>
    <p v-else-if="!shown.length" class="card text-sm text-ink-muted">No purchase orders are awaiting delivery in your warehouses.</p>
    <ul v-else class="space-y-3">
        <li v-for="o in shown" :key="o.id">
            <Link :href="`/tasks/receiving/${o.id}`" class="card flex flex-wrap items-center gap-4 transition hover:shadow-md">
                <span class="grid size-11 shrink-0 place-items-center rounded-lg bg-brand/10 text-brand"><AppIcon name="inbox" /></span>
                <span class="min-w-0 flex-1">
                    <span class="block font-semibold">{{ o.document_number }}</span>
                    <span class="block text-sm text-ink-muted">
                        <template v-if="o.expected_delivery_date">Expected {{ formatDate(o.expected_delivery_date) }}</template>
                        <template v-if="o.supplier_reference"> · Supplier ref. {{ o.supplier_reference }}</template>
                    </span>
                </span>
                <StatusBadge :value="o.status" :label="o.status_label" />
                <AppIcon name="chevron-right" class="text-ink-muted" />
            </Link>
        </li>
    </ul>
    <div v-if="cursor" class="mt-4 text-center">
        <UiButton :loading="loading" @click="load(true)">Load more</UiButton>
    </div>
</template>
