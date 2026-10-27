<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppIcon from '@/components/ui/AppIcon.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import { get } from '@/lib/api';
import { formatMoney } from '@/lib/format';
import { addToCart, cart, type PortalItem } from '@/lib/portal';
import { notify } from '@/lib/toast';
import type { NextAction } from '@/types/shared';

const props = defineProps<{ productId: string }>();
const item = ref<(PortalItem & { description_long: string | null; max_order_qty: string | null }) | null>(null);
const quantity = ref(1);
const error = ref<unknown>(null);
const nextActions = computed<NextAction[]>(() => (cart.lines.length
    ? [{ label: `Go to your cart (${cart.lines.length})`, icon: 'cart', href: '/portal/cart', hint: 'Order, or ask us for a quotation.' }]
    : []));

onMounted(async () => {
    try {
        item.value = (await get(`/api/v1/portal/catalog/items/${props.productId}`)).data;
        quantity.value = Number(item.value!.min_order_qty ?? item.value!.order_multiple ?? 1) || 1;
    } catch (e) {
        error.value = e;
    }
});

function add() {
    const i = item.value!;
    addToCart({ item_id: i.id, name: i.name, sku: i.sku, unit: i.unit, price: i.price, currency: i.currency }, quantity.value);
    notify(`${quantity.value} × ${i.name} added to your cart.`);
}
</script>

<template>
    <Head :title="item?.name ?? 'Product'" />
    <PageHeader :title="item?.name ?? 'Product'" :subtitle="item?.sku" back="/portal/catalog" back-label="Products" :next="nextActions" />
    <ErrorSummary :error="error" />
    <div v-if="item" class="grid gap-6 md:grid-cols-[18rem_1fr]">
        <div class="grid aspect-square place-items-center overflow-hidden rounded-brand border border-line bg-surface">
            <img v-if="item.image_url" :src="item.image_url" :alt="item.name" class="size-full object-contain">
            <AppIcon v-else name="package" :size="48" class="text-ink-muted" />
        </div>
        <div class="space-y-4">
            <p v-if="item.category" class="text-sm text-ink-muted">{{ item.category }}</p>
            <p class="text-2xl font-bold">
                <template v-if="item.price">{{ formatMoney(item.price, item.currency) }} <span class="text-base font-normal text-ink-muted">per {{ item.unit_name ?? item.unit }}</span></template>
                <span v-else class="text-lg font-normal text-ink-muted">Price on request — add it to a quotation request.</span>
            </p>
            <p v-if="item.availability" class="text-sm font-semibold">
                {{ { in_stock: 'In stock', low_stock: 'Low stock', out_of_stock: 'Out of stock' }[item.availability.band] }}
                <template v-if="item.availability.quantity"> · {{ item.availability.quantity }} {{ item.unit }} available</template>
            </p>
            <p v-if="item.description" class="text-ink">{{ item.description }}</p>
            <p v-if="item.description_long" class="whitespace-pre-line text-sm text-ink-muted">{{ item.description_long }}</p>
            <p class="text-xs text-ink-muted">
                <template v-if="item.min_order_qty">Minimum order {{ item.min_order_qty }} {{ item.unit }}. </template>
                <template v-if="item.order_multiple">Sold in multiples of {{ item.order_multiple }}. </template>
                <template v-if="item.max_order_qty">Up to {{ item.max_order_qty }} per order.</template>
            </p>
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label for="p-qty" class="label">Quantity ({{ item.unit }})</label>
                    <input id="p-qty" v-model.number="quantity" type="number" min="1" :step="item.order_multiple ?? 1" class="input w-32" inputmode="decimal">
                </div>
                <UiButton variant="primary" icon="cart" :disabled="!(quantity > 0)" @click="add">Add to cart</UiButton>
                <Link href="/portal/cart" class="btn btn-ghost">Go to cart</Link>
            </div>
        </div>
    </div>
</template>
