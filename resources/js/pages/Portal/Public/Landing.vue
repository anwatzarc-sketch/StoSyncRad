<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppIcon from '@/components/ui/AppIcon.vue';
import { useShared } from '@/composables/useShared';
import { get } from '@/lib/api';

/**
 * Public entry point of the Customer Portal (CP-1.1 section 3). It describes only what the system does
 * (catalogue, quotations, orders, invoices, payment confirmation, delivery tracking) and shows no
 * prices, stock or account data. "Start engaging" opens the portal: visitors are asked to sign in or
 * register there.
 */
interface Info {
    company_name: string | null;
    tagline: string | null;
    registration_mode: 'approval' | 'invitation_only' | 'closed';
    direct_ordering: boolean;
    categories: Array<{ name: string; item_count: number }>;
    contact: { phone?: string; email?: string; website?: string; city?: string };
}

const { brand } = useShared();
const info = ref<Info | null>(null);
const company = computed(() => info.value?.company_name ?? brand.value.company_name ?? brand.value.product.name);
const canRegister = computed(() => (info.value?.registration_mode ?? 'approval') === 'approval');

const features = computed(() => [
    { icon: 'search', title: 'Browse products', text: 'Search the products we offer online, with descriptions, units and availability.' },
    { icon: 'clipboard', title: 'Request quotations', text: 'Ask for a price on any quantity; review, ask for changes and accept online.' },
    { icon: 'cart', title: info.value?.direct_ordering === false ? 'Order from quotations' : 'Place orders', text: 'Order directly from the catalogue or from an accepted quotation.' },
    { icon: 'file', title: 'Invoices & documents', text: 'See your invoices, what you have paid and what is still due, and download them.' },
    { icon: 'coins', title: 'Confirm payments', text: 'Upload your bank slip or transfer screenshot; our finance team verifies it.' },
    { icon: 'truck', title: 'Track deliveries', text: 'Follow each order from confirmation to delivery and confirm what you received.' },
]);

onMounted(async () => {
    try {
        info.value = (await get<{ data: Info }>('/api/v1/portal/public/info')).data;
    } catch {
        info.value = null;
    }
});
</script>

<template>
    <Head :title="`Customer portal · ${company}`" />
    <section class="bg-surface-muted">
        <div class="mx-auto grid max-w-6xl gap-8 px-4 py-12 md:grid-cols-[1.2fr_1fr] md:items-center md:py-20">
            <div>
                <p class="mb-3 text-sm font-semibold uppercase tracking-wide text-accent">Customer portal</p>
                <h1 class="text-3xl font-bold leading-tight sm:text-4xl">Buy from {{ company }} online</h1>
                <p class="mt-4 max-w-xl text-lg text-ink-muted">
                    {{ info?.tagline || 'Find products, get quotations, place orders, see your invoices, confirm payments and follow every delivery — in one place.' }}
                </p>
                <div class="mt-8 flex flex-wrap gap-3">
                    <Link v-if="canRegister" href="/portal/register" class="btn btn-primary min-h-12 px-6 text-base">Sign up</Link>
                    <Link href="/portal/dashboard" class="btn btn-accent min-h-12 px-6 text-base">Start engaging</Link>
                    <Link href="/portal/login" class="btn btn-secondary min-h-12 px-6 text-base">Sign in</Link>
                </div>
                <p v-if="info && info.registration_mode === 'invitation_only'" class="mt-4 text-sm text-ink-muted">
                    New accounts are by invitation. Contact us below to be invited.
                </p>
                <p v-else-if="info && info.registration_mode === 'closed'" class="mt-4 text-sm text-ink-muted">New registrations are closed at the moment.</p>
            </div>
            <div class="card space-y-4 p-6">
                <h2 class="text-lg font-semibold">How it works</h2>
                <ol class="space-y-4">
                    <li v-for="(step, i) in ['Register your company and confirm your e-mail. Set up two-step verification for a secure account.',
                                             'We check your details and open your account.',
                                             'Browse products, request a quotation or place an order. We confirm it and prepare your goods.',
                                             'Track the delivery, confirm what arrived, and confirm your payment with your bank slip.']"
                        :key="i" class="flex gap-3">
                        <span class="grid size-8 shrink-0 place-items-center rounded-full bg-accent font-bold text-accent-contrast">{{ i + 1 }}</span>
                        <span class="pt-1 text-sm">{{ step }}</span>
                    </li>
                </ol>
            </div>
        </div>
    </section>

    <section class="mx-auto max-w-6xl px-4 py-12">
        <h2 class="text-2xl font-bold">What you can do</h2>
        <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div v-for="f in features" :key="f.title" class="card">
                <AppIcon :name="f.icon" :size="24" class="text-accent" />
                <h3 class="mt-3 font-semibold">{{ f.title }}</h3>
                <p class="mt-1 text-sm text-ink-muted">{{ f.text }}</p>
            </div>
        </div>
    </section>

    <section v-if="info?.categories?.length" class="mx-auto max-w-6xl px-4 pb-12">
        <h2 class="text-2xl font-bold">Product range</h2>
        <ul class="mt-4 flex flex-wrap gap-2">
            <li v-for="c in info.categories" :key="c.name" class="rounded-full border border-line bg-surface px-3 py-1 text-sm">
                {{ c.name }} <span class="text-ink-muted">· {{ c.item_count }}</span>
            </li>
        </ul>
        <p class="mt-2 text-sm text-ink-muted">Sign in to see products, prices and availability.</p>
    </section>

    <section v-if="info && Object.keys(info.contact).length" class="mx-auto max-w-6xl px-4 pb-12">
        <div class="card flex flex-wrap items-center gap-x-8 gap-y-2">
            <h2 class="w-full text-lg font-semibold sm:w-auto">Contact {{ company }}</h2>
            <a v-if="info.contact.phone" :href="`tel:${info.contact.phone}`" class="text-sm underline">{{ info.contact.phone }}</a>
            <a v-if="info.contact.email" :href="`mailto:${info.contact.email}`" class="text-sm underline">{{ info.contact.email }}</a>
            <a v-if="info.contact.website" :href="info.contact.website" class="text-sm underline" rel="noopener" target="_blank">{{ info.contact.website }}</a>
            <span v-if="info.contact.city" class="text-sm text-ink-muted">{{ info.contact.city }}</span>
        </div>
    </section>
</template>
