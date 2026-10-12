<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppIcon from '@/components/ui/AppIcon.vue';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import { get } from '@/lib/api';
import { formatMoney } from '@/lib/format';
import { addToCart, type PortalItem } from '@/lib/portal';
import { notify } from '@/lib/toast';

/** Products published online, searchable and filterable; prices and availability as configured by the seller. */

const items = ref<PortalItem[]>([]);
const categories = ref<Array<{ id: string; name: string; item_count: number }>>([]);
const q = ref(new URLSearchParams(window.location.search).get('q') ?? '');
const category = ref('');
const page = ref(1);
const total = ref(0);
const loading = ref(false);
const error = ref<unknown>(null);
let timer: ReturnType<typeof setTimeout> | undefined;

const BANDS: Record<string, [string, string]> = {
    in_stock: ['In stock', 'text-success'], low_stock: ['Low stock', 'text-warning'], out_of_stock: ['Out of stock', 'text-danger'],
};

async function load(append = false) {
    loading.value = true;
    try {
        const r = await get('/api/v1/portal/catalog', { q: q.value || undefined, category_id: category.value || undefined, page: page.value });
        items.value = append ? [...items.value, ...r.data] : r.data;
        total.value = r.meta.total;
    } catch (e) {
        error.value = e;
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    categories.value = (await get('/api/v1/portal/catalog/categories').catch(() => ({ data: [] }))).data;
    await load();
});
watch([q, category], () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        page.value = 1;
        void load();
    }, 250);
});

function add(item: PortalItem) {
    addToCart({ item_id: item.id, name: item.name, sku: item.sku, unit: item.unit, price: item.price, currency: item.currency },
        Number(item.min_order_qty ?? item.order_multiple ?? 1) || 1);
    notify(`${item.name} added to your cart.`);
}
</script>

<template>
    <Head title="Products" />
    <div class="flex flex-wrap items-end gap-3">
        <h1 class="mr-auto text-2xl font-bold">Products</h1>
        <Link href="/portal/cart" class="btn btn-secondary"><AppIcon name="cart" :size="18" /> Cart</Link>
    </div>
    <div class="mt-4 grid gap-3 sm:grid-cols-[1fr_16rem]">
        <label class="relative">
            <span class="sr-only">Search products</span>
            <AppIcon name="search" :size="18" class="pointer-events-none absolute left-3 top-3 text-ink-muted" />
            <input v-model="q" type="search" class="input pl-10" placeholder="Search by name or code">
        </label>
        <label>
            <span class="sr-only">Category</span>
            <select v-model="category" class="input">
                <option value="">All categories</option>
                <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }} ({{ c.item_count }})</option>
            </select>
        </label>
    </div>
    <ErrorSummary :error="error" class="mt-4" />

    <p v-if="!loading && !items.length" class="mt-10 text-center text-ink-muted">No products match your search.</p>
    <ul class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <li v-for="item in items" :key="item.id" class="card flex flex-col">
            <Link :href="`/portal/catalog/${item.id}`" class="flex flex-1 gap-3">
                <span class="grid size-16 shrink-0 place-items-center overflow-hidden rounded-brand bg-surface-muted">
                    <img v-if="item.image_url" :src="item.image_url" alt="" class="size-full object-cover" loading="lazy">
                    <AppIcon v-else name="package" :size="24" class="text-ink-muted" />
                </span>
                <span class="min-w-0">
                    <span class="block font-semibold leading-snug">{{ item.name }}</span>
                    <span class="block text-xs text-ink-muted">{{ item.sku }}<template v-if="item.category"> · {{ item.category }}</template></span>
                    <span v-if="item.availability" :class="['mt-1 block text-xs font-semibold', BANDS[item.availability.band]?.[1]]">
                        {{ BANDS[item.availability.band]?.[0] }}<template v-if="item.availability.quantity"> · {{ item.availability.quantity }} {{ item.unit }}</template>
                    </span>
                </span>
            </Link>
            <div class="mt-3 flex items-center gap-2 border-t border-line pt-3">
                <span class="mr-auto font-semibold">
                    <template v-if="item.price">{{ formatMoney(item.price, item.currency) }} <span class="text-xs font-normal text-ink-muted">/ {{ item.unit }}</span></template>
                    <span v-else class="text-sm font-normal text-ink-muted">Price on request</span>
                </span>
                <UiButton icon="plus" @click="add(item)">Add</UiButton>
            </div>
        </li>
    </ul>
    <div v-if="items.length < total" class="mt-6 text-center">
        <UiButton :loading="loading" @click="page++; load(true)">Show more</UiButton>
    </div>
</template>
