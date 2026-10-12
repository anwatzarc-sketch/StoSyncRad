<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import { post } from '@/lib/api';
import { formatMoney } from '@/lib/format';
import { attemptKey, completeAttempt } from '@/lib/idempotency';
import { cart, clearCart, setQuantity } from '@/lib/portal';
import { notify } from '@/lib/toast';

/**
 * The cart turns into an order (every product has a list price) or a quotation request. Totals shown
 * here are an estimate; the server prices the order and the confirmation shows the agreed total.
 */
const reference = ref('');
const notes = ref('');
const address = ref({ contact: '', phone: '', line1: '', city: '' });
const busy = ref<'order' | 'quote' | null>(null);
const error = ref<unknown>(null);

const allPriced = computed(() => cart.lines.length > 0 && cart.lines.every((l) => l.price !== null));
const currencies = computed(() => [...new Set(cart.lines.filter((l) => l.price).map((l) => l.currency))]);
const estimate = computed(() => cart.lines.reduce((sum, l) => sum + (l.price ? Number(l.price) * l.quantity : 0), 0));
const lines = () => cart.lines.map((l) => ({ item_id: l.item_id, quantity: String(l.quantity) }));

async function placeOrder() {
    busy.value = 'order';
    error.value = null;
    try {
        const addr = Object.values(address.value).some((v) => v.trim()) ? address.value : null;
        const { data } = await post('/api/v1/portal/orders', { lines: lines(), customer_reference: reference.value || null, notes: notes.value || null,
            shipping_address: addr }, { idempotencyKey: attemptKey('portal-order') });
        completeAttempt('portal-order');
        clearCart();
        notify(`Order ${data.document_number} placed. We will confirm it shortly.`);
        router.visit(`/portal/orders/${data.id}`);
    } catch (e) {
        error.value = e;
    } finally {
        busy.value = null;
    }
}

async function requestQuote() {
    busy.value = 'quote';
    error.value = null;
    try {
        const { data } = await post('/api/v1/portal/quotations', { lines: lines(), customer_reference: reference.value || null, note: notes.value || null },
            { idempotencyKey: attemptKey('portal-quote') });
        completeAttempt('portal-quote');
        clearCart();
        notify(`Quotation request ${data.document_number} sent.`);
        router.visit(`/portal/quotations/${data.id}`);
    } catch (e) {
        error.value = e;
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <Head title="Cart" />
    <PageHeader title="Your cart" back="/portal/catalog" />
    <ErrorSummary :error="error" />
    <div v-if="!cart.lines.length" class="card text-center">
        <p class="text-ink-muted">Your cart is empty.</p>
        <Link href="/portal/catalog" class="btn btn-primary mt-4">Browse products</Link>
    </div>
    <div v-else class="grid gap-6 lg:grid-cols-[2fr_1fr]">
        <section class="card">
            <ul class="divide-y divide-line">
                <li v-for="l in cart.lines" :key="l.item_id" class="flex flex-wrap items-center gap-3 py-3">
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">{{ l.name }}</p>
                        <p class="text-xs text-ink-muted">{{ l.sku }} · {{ l.price ? `${formatMoney(l.price, l.currency)} / ${l.unit}` : 'Price on request' }}</p>
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <span class="sr-only">Quantity of {{ l.name }}</span>
                        <input :value="l.quantity" type="number" min="0" class="input w-24" inputmode="decimal"
                               @change="setQuantity(l.item_id, Number(($event.target as HTMLInputElement).value))">
                        <span class="text-ink-muted">{{ l.unit }}</span>
                    </label>
                    <UiButton variant="ghost" icon="x" :aria-label="`Remove ${l.name}`" @click="setQuantity(l.item_id, 0)" />
                </li>
            </ul>
            <p v-if="allPriced && currencies.length === 1" class="mt-3 text-right font-semibold">Estimated total {{ formatMoney(estimate, currencies[0]) }}</p>
        </section>
        <section class="card space-y-4">
            <div>
                <label for="c-ref" class="label">Your reference (PO number)</label>
                <input id="c-ref" v-model="reference" class="input" maxlength="120">
            </div>
            <fieldset class="space-y-2">
                <legend class="label">Delivery address (if different from your usual one)</legend>
                <input v-model="address.contact" class="input" placeholder="Contact person" aria-label="Contact person">
                <input v-model="address.phone" class="input" placeholder="Phone" type="tel" aria-label="Phone">
                <input v-model="address.line1" class="input" placeholder="Street / building" aria-label="Street">
                <input v-model="address.city" class="input" placeholder="City" aria-label="City">
            </fieldset>
            <div>
                <label for="c-notes" class="label">Notes</label>
                <textarea id="c-notes" v-model="notes" class="input min-h-20" />
            </div>
            <UiButton v-if="allPriced" variant="primary" class="w-full" :loading="busy === 'order'" :disabled="!!busy" @click="placeOrder">Place order</UiButton>
            <UiButton :variant="allPriced ? 'secondary' : 'primary'" class="w-full" :loading="busy === 'quote'" :disabled="!!busy" @click="requestQuote">
                Request a quotation
            </UiButton>
            <p class="text-xs text-ink-muted">
                {{ allPriced ? 'Orders are confirmed by our team before we reserve and send the goods.' : 'Some products have no list price: we will send you a quotation.' }}
            </p>
        </section>
    </div>
</template>
