<script setup lang="ts">
import { computed, onMounted, reactive } from 'vue';
import { Link } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import AppIcon from '@/components/ui/AppIcon.vue';
import { get } from '@/lib/api';
import { formatDate, formatQuantity, relativeTime } from '@/lib/format';
import { useShared } from '@/composables/useShared';

/**
 * Role-aware overview (Chapter 12 §12.6): each card is a work queue the person may act on (shown
 * only when the server put the resource in their navigation) and links to the filtered list.
 */
interface Queue { key: string; title: string; resource: string; status: string; icon: string }

const QUEUES: Queue[] = [
    { key: 'pr', title: 'Purchase requests to approve', resource: 'purchase-requests', status: 'submitted', icon: 'cart' },
    { key: 'po', title: 'Purchase orders to approve', resource: 'purchase-orders', status: 'submitted', icon: 'cart' },
    { key: 'inbound', title: 'Inbound orders', resource: 'purchase-orders', status: 'sent', icon: 'truck' },
    { key: 'qc', title: 'Receipts in inspection', resource: 'goods-receipts', status: 'in_inspection', icon: 'inbox' },
    { key: 'gr', title: 'Receipts to approve', resource: 'goods-receipts', status: 'pending_approval', icon: 'inbox' },
    { key: 'putaway', title: 'Put-away tasks', resource: 'putaway-tasks', status: 'open', icon: 'boxes' },
    { key: 'to-approve', title: 'Transfers to approve', resource: 'transfers', status: 'submitted', icon: 'truck' },
    { key: 'to-dispatch', title: 'Transfers to dispatch', resource: 'transfers', status: 'approved', icon: 'truck' },
    { key: 'in-transit', title: 'Transfers in transit', resource: 'transfers', status: 'in_transit', icon: 'route' },
    { key: 'discrepancies', title: 'Open discrepancies', resource: 'transfer-discrepancies', status: 'open', icon: 'alert' },
    { key: 'requests', title: 'Stock requests to approve', resource: 'stock-requests', status: 'submitted', icon: 'clipboard' },
    { key: 'issues', title: 'Issues to approve', resource: 'stock-issues', status: 'pending_approval', icon: 'clipboard' },
    { key: 'orders', title: 'Sales orders to pick', resource: 'sales-orders', status: 'confirmed', icon: 'bag' },
    { key: 'counting', title: 'Stocktakes in counting', resource: 'stocktakes', status: 'counting', icon: 'list-check' },
    { key: 'review', title: 'Stocktakes to review', resource: 'stocktakes', status: 'review', icon: 'list-check' },
    { key: 'adjustments', title: 'Adjustments to approve', resource: 'stock-adjustments', status: 'pending_approval', icon: 'sliders' },
];

const { auth, nav } = useShared();
const allowed = computed(() => new Set(nav.value.flatMap((s) => s.items.map((i) => i.href))));
const visible = computed(() => QUEUES.filter((q) => allowed.value.has(`/r/${q.resource}`)));
const showLowStock = computed(() => allowed.value.has('/planning/replenishment'));
const data = reactive<Record<string, { rows: Record<string, any>[]; more: boolean; loading: boolean; failed: boolean }>>({});
const lowStock = reactive<{ rows: Record<string, any>[]; loading: boolean }>({ rows: [], loading: true });

onMounted(() => {
    for (const q of visible.value) {
        data[q.key] = { rows: [], more: false, loading: true, failed: false };
        get(`/api/v1/${q.resource}`, { filter: { status: q.status }, per_page: 5 })
            .then((r) => Object.assign(data[q.key], { rows: r.data, more: !!r.meta?.next_cursor }))
            .catch(() => (data[q.key].failed = true))
            .finally(() => (data[q.key].loading = false));
    }
    if (showLowStock.value) {
        get('/api/v1/planning/replenishment').then((r) => (lowStock.rows = (r.data ?? []).slice(0, 6))).catch(() => undefined)
            .finally(() => (lowStock.loading = false));
    }
});

const label = (row: Record<string, any>) => row.document_number ?? row.code ?? row.name ?? row.id;
const nonEmpty = computed(() => visible.value.filter((q) => data[q.key]?.loading || (data[q.key]?.rows.length ?? 0) > 0));
const idle = computed(() => visible.value.length > 0 && visible.value.every((q) => data[q.key] && !data[q.key].loading && data[q.key].rows.length === 0));
</script>

<template>
    <PageHeader title="Overview" :subtitle="auth ? `Welcome, ${auth.account.display_name}` : null" />

    <section v-if="showLowStock" class="card mb-6">
        <div class="mb-3 flex items-center justify-between">
            <h2 class="flex items-center gap-2 font-semibold"><AppIcon name="refresh" /> Below reorder point</h2>
            <Link href="/planning/replenishment" class="text-sm text-brand underline">Replenishment</Link>
        </div>
        <p v-if="!lowStock.loading && lowStock.rows.length === 0" class="text-sm text-ink-muted">Nothing below its reorder point.</p>
        <ul v-else class="divide-y divide-line">
            <li v-for="(s, i) in lowStock.rows" :key="i" class="flex items-center justify-between gap-3 py-2 text-sm">
                <span class="min-w-0 truncate"><strong>{{ s.sku }}</strong> {{ s.item_name }}<template v-if="s.warehouse_code"> · {{ s.warehouse_code }}</template></span>
                <span class="tabular-nums text-ink-muted">available {{ formatQuantity(s.available) }} · order {{ formatQuantity(s.proposed_quantity) }}</span>
            </li>
        </ul>
    </section>

    <p v-if="idle" class="card text-center text-sm text-ink-muted">You are all caught up — nothing is waiting for you.</p>
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <section v-for="q in nonEmpty" :key="q.key" class="card">
            <div class="mb-2 flex items-center justify-between gap-2">
                <h2 class="flex items-center gap-2 font-semibold"><AppIcon :name="q.icon" /> {{ q.title }}</h2>
                <span class="rounded-full bg-accent/10 px-2 text-sm font-semibold text-accent">{{ data[q.key].rows.length }}{{ data[q.key].more ? '+' : '' }}</span>
            </div>
            <div v-if="data[q.key].loading" class="h-16 animate-pulse rounded bg-surface-muted" />
            <ul v-else class="divide-y divide-line">
                <li v-for="row in data[q.key].rows" :key="row.id">
                    <Link :href="`/r/${q.resource}/${row.id}`" class="flex min-h-11 items-center justify-between gap-2 py-1 text-sm hover:text-brand">
                        <span class="truncate font-medium">{{ label(row) }}</span>
                        <span class="shrink-0 text-xs text-ink-muted">{{ row.document_date ? formatDate(row.document_date) : relativeTime(row.created_at) }}</span>
                    </Link>
                </li>
            </ul>
            <Link :href="`/r/${q.resource}`" class="mt-2 inline-block text-sm text-brand underline">Open list</Link>
        </section>
    </div>
</template>
