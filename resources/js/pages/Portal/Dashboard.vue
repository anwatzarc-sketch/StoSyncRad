<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppIcon from '@/components/ui/AppIcon.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import { get, post } from '@/lib/api';
import { formatDateTime, formatMoney } from '@/lib/format';
import { profile } from '@/lib/portal';

/** Customer home: what needs attention (quotations to review, unpaid invoices, payments under review) and recent news. */
interface Dashboard {
    quotations_to_review: number;
    quotations_in_progress: number;
    open_orders: number;
    unpaid_invoices: number;
    overdue_invoices: number;
    balance_due: Array<{ currency: string; amount: string }>;
    payments_under_review: number;
    payments_needing_info: number;
    notifications: Array<{ id: string; title: string; link: string | null; read: boolean; created_at: string }>;
    credit: { limit: string | null; exposure: string; available: string | null; currency: string | null };
}

const data = ref<Dashboard | null>(null);
const error = ref<unknown>(null);

onMounted(async () => {
    try {
        data.value = (await get<{ data: Dashboard }>('/api/v1/portal/dashboard')).data;
    } catch (e) {
        error.value = e;
    }
});

async function open(n: Dashboard['notifications'][number]) {
    if (!n.read) {
        await post(`/api/v1/portal/notifications/${n.id}/read`).catch(() => undefined);
    }
    if (n.link) {
        window.location.assign(n.link);
    }
}
</script>

<template>
    <Head title="Customer portal" />
    <h1 class="text-2xl font-bold">Welcome{{ profile ? `, ${profile.account.name.split(' ')[0]}` : '' }}</h1>
    <p v-if="profile" class="text-ink-muted">{{ profile.customer.name }}</p>
    <ErrorSummary :error="error" class="mt-4" />

    <div v-if="data" class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Link href="/portal/quotations" class="card block hover:border-accent">
            <p class="text-sm text-ink-muted">Quotations to review</p>
            <p class="mt-1 text-3xl font-bold">{{ data.quotations_to_review }}</p>
            <p class="text-xs text-ink-muted">{{ data.quotations_in_progress }} being prepared by us</p>
        </Link>
        <Link href="/portal/orders" class="card block hover:border-accent">
            <p class="text-sm text-ink-muted">Open orders</p>
            <p class="mt-1 text-3xl font-bold">{{ data.open_orders }}</p>
        </Link>
        <Link href="/portal/invoices" class="card block hover:border-accent">
            <p class="text-sm text-ink-muted">Unpaid invoices</p>
            <p class="mt-1 text-3xl font-bold">{{ data.unpaid_invoices }}</p>
            <p v-for="b in data.balance_due" :key="b.currency" class="text-xs text-ink-muted">{{ formatMoney(b.amount, b.currency) }} due</p>
            <p v-if="data.overdue_invoices" class="text-xs font-semibold text-danger">{{ data.overdue_invoices }} overdue</p>
        </Link>
        <Link href="/portal/payments" class="card block hover:border-accent">
            <p class="text-sm text-ink-muted">Payments under review</p>
            <p class="mt-1 text-3xl font-bold">{{ data.payments_under_review }}</p>
            <p v-if="data.payments_needing_info" class="text-xs font-semibold text-warning">{{ data.payments_needing_info }} need more information</p>
        </Link>
    </div>

    <section v-if="data?.credit.limit !== null && data?.credit.limit !== undefined" class="card mt-4 flex flex-wrap items-center gap-x-8 gap-y-2" aria-label="Credit">
        <p class="text-sm text-ink-muted">Credit limit <strong class="block text-lg text-ink">{{ formatMoney(data.credit.limit, data.credit.currency) }}</strong></p>
        <p class="text-sm text-ink-muted">In use (unpaid and open orders) <strong class="block text-lg text-ink">{{ formatMoney(data.credit.exposure, data.credit.currency) }}</strong></p>
        <p class="text-sm text-ink-muted">Available for new orders
            <strong :class="['block text-lg', Number(data.credit.available) > 0 ? 'text-ink' : 'text-danger']">{{ formatMoney(data.credit.available, data.credit.currency) }}</strong>
        </p>
    </section>

    <div class="mt-6 grid gap-4 lg:grid-cols-[2fr_1fr]">
        <section class="card">
            <h2 class="mb-3 text-lg font-semibold">Latest updates</h2>
            <p v-if="data && !data.notifications.length" class="text-sm text-ink-muted">Nothing new yet.</p>
            <ul class="divide-y divide-line">
                <li v-for="n in data?.notifications ?? []" :key="n.id">
                    <button type="button" class="flex min-h-12 w-full items-center gap-3 py-2 text-left" @click="open(n)">
                        <span :class="['size-2 shrink-0 rounded-full', n.read ? 'bg-transparent' : 'bg-accent']" aria-hidden="true" />
                        <span class="min-w-0 flex-1" :class="{ 'font-semibold': !n.read }">{{ n.title }}</span>
                        <span class="shrink-0 text-xs text-ink-muted">{{ formatDateTime(n.created_at) }}</span>
                    </button>
                </li>
            </ul>
        </section>
        <section class="card space-y-2">
            <h2 class="text-lg font-semibold">Quick actions</h2>
            <Link href="/portal/catalog" class="btn btn-primary w-full justify-start"><AppIcon name="search" :size="18" /> Find products</Link>
            <Link href="/portal/cart" class="btn btn-secondary w-full justify-start"><AppIcon name="cart" :size="18" /> Open cart</Link>
            <Link href="/portal/invoices" class="btn btn-secondary w-full justify-start"><AppIcon name="coins" :size="18" /> Confirm a payment</Link>
        </section>
    </div>
</template>
