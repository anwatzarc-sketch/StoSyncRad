<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import { get } from '@/lib/api';
import { formatDate, formatMoney } from '@/lib/format';

const rows = ref<Array<Record<string, any>>>([]);
const loaded = ref(false);
const error = ref<unknown>(null);

onMounted(async () => {
    try {
        rows.value = (await get('/api/v1/portal/orders')).data;
    } catch (e) {
        error.value = e;
    } finally {
        loaded.value = true;
    }
});
</script>

<template>
    <Head title="Orders" />
    <PageHeader title="Orders" />
    <ErrorSummary :error="error" />
    <div class="card">
        <p v-if="loaded && !rows.length" class="py-8 text-center text-ink-muted">No orders yet. <Link href="/portal/catalog" class="underline">Browse products</Link>.</p>
        <ul class="divide-y divide-line">
            <li v-for="o in rows" :key="o.id">
                <Link :href="`/portal/orders/${o.id}`" class="flex flex-wrap items-center gap-3 py-3 hover:bg-surface-muted">
                    <span class="min-w-0 flex-1">
                        <span class="block font-medium">{{ o.document_number }}<template v-if="o.reference"> · {{ o.reference }}</template></span>
                        <span class="block text-sm text-ink-muted">
                            {{ formatDate(o.document_date) }}<template v-if="o.from_quotation"> · from quotation {{ o.from_quotation }}</template>
                        </span>
                    </span>
                    <span class="font-semibold">{{ formatMoney(o.total, o.currency) }}</span>
                    <StatusBadge :value="o.status" :label="o.status === 'draft' ? 'Awaiting confirmation' : o.status_label" />
                </Link>
            </li>
        </ul>
    </div>
</template>
