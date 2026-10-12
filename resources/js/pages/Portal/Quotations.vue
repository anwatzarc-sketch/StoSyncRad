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
        rows.value = (await get('/api/v1/portal/quotations')).data;
    } catch (e) {
        error.value = e;
    } finally {
        loaded.value = true;
    }
});
</script>

<template>
    <Head title="Quotations" />
    <PageHeader title="Quotations" />
    <ErrorSummary :error="error" />
    <div class="card">
        <p v-if="loaded && !rows.length" class="py-8 text-center text-ink-muted">
            No quotations yet. Add products to your cart and choose <strong>Request a quotation</strong>.
        </p>
        <ul class="divide-y divide-line">
            <li v-for="q in rows" :key="q.id">
                <Link :href="`/portal/quotations/${q.id}`" class="flex flex-wrap items-center gap-3 py-3 hover:bg-surface-muted">
                    <span class="min-w-0 flex-1">
                        <span class="block font-medium">{{ q.document_number }}<template v-if="q.reference"> · {{ q.reference }}</template></span>
                        <span class="block text-sm text-ink-muted">
                            Requested {{ formatDate(q.created_at) }}<template v-if="q.valid_until"> · valid until {{ formatDate(q.valid_until) }}</template>
                        </span>
                    </span>
                    <span v-if="q.total" class="font-semibold">{{ formatMoney(q.total, q.currency) }}</span>
                    <StatusBadge :value="q.status" :label="q.status === 'sent' ? 'Ready for you' : q.status_label" />
                </Link>
            </li>
        </ul>
    </div>
</template>
