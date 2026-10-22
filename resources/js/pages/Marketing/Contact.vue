<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppIcon from '@/components/ui/AppIcon.vue';
import CheckList from '@/components/marketing/CheckList.vue';
import PageHero from '@/components/marketing/PageHero.vue';
import { useShared } from '@/composables/useShared';

/**
 * Product website: sales contact (config/product.php vendor_email / vendor_phone / vendor_url), with the
 * deployment's support contacts from the brand kit as fallback, and the ways in for existing users.
 */
const { brand, feature } = useShared();
const product = computed(() => brand.value.product);
const email = computed(() => product.value.vendor_email ?? brand.value.support_email);
const phone = computed(() => product.value.vendor_phone ?? brand.value.support_phone);
const website = computed(() => product.value.vendor_url ?? brand.value.support_url);
const team = computed(() => product.value.vendor ?? product.value.name);

const demoMail = computed(() => {
    const subject = `${product.value.name} demo request`;
    const body = [
        'Hello,', '', `We would like a demo of ${product.value.name}.`, '',
        'Company:', 'Number of branches / warehouses:', 'What we stock (e.g. medicines, retail goods):', 'Preferred date and time:', 'Phone:',
    ].join('\n');

    return `mailto:${email.value}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
});

const next = [
    'We reply to agree a time that suits your team',
    'A walkthrough built around your kind of items and branches',
    'A rollout outline: what we need from you, and how long it takes',
];
</script>

<template>
    <div>
        <Head title="Contact" />
        <PageHero
            eyebrow="Contact"
            :title="`Talk to the ${product.name} team`"
            lead="Book a demo, plan your rollout, or ask us anything about how your stock flows."
        />

        <section class="mk-container grid gap-6 py-16 sm:py-20 lg:grid-cols-[1.3fr_1fr]">
            <div class="self-start rounded-3xl border border-line bg-surface p-8 shadow-sm sm:p-10">
                <h2 class="text-2xl font-bold tracking-tight">Book a demo</h2>
                <p class="mt-2 leading-relaxed text-ink-muted">Tell us a little about your company and we will prepare a demo around your own items and branches.</p>

                <div v-if="email || phone" class="mt-8 grid gap-4 sm:grid-cols-2">
                    <a v-if="email" :href="demoMail" class="group flex items-start gap-4 rounded-2xl border border-line p-5 transition hover:border-product-accent/50 hover:shadow-md">
                        <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-product-accent/10 text-product-accent"><AppIcon name="mail" :size="22" /></span>
                        <span class="min-w-0">
                            <span class="block font-semibold">Email us</span>
                            <span class="mt-0.5 block truncate text-sm text-ink-muted">{{ email }}</span>
                            <span class="mt-2 inline-flex items-center gap-1 text-sm font-semibold text-product-accent">Request a demo<AppIcon name="arrow-right" :size="14" class="transition group-hover:translate-x-0.5" /></span>
                        </span>
                    </a>
                    <a v-if="phone" :href="`tel:${phone.replace(/\s+/g, '')}`" class="group flex items-start gap-4 rounded-2xl border border-line p-5 transition hover:border-product-accent/50 hover:shadow-md">
                        <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-product-accent/10 text-product-accent"><AppIcon name="phone" :size="22" /></span>
                        <span class="min-w-0">
                            <span class="block font-semibold">Call us</span>
                            <span class="mt-0.5 block text-sm text-ink-muted">{{ phone }}</span>
                            <span class="mt-2 inline-flex items-center gap-1 text-sm font-semibold text-product-accent">Talk to sales<AppIcon name="arrow-right" :size="14" class="transition group-hover:translate-x-0.5" /></span>
                        </span>
                    </a>
                </div>
                <p v-else class="mt-8 rounded-2xl border border-dashed border-line p-5 text-sm text-ink-muted">
                    Reach the {{ team }} team through your usual contact — or sign in and use the support details on the sign-in page.
                </p>

                <a v-if="website" :href="website" target="_blank" rel="noopener" class="mt-6 inline-flex items-center gap-2 text-sm font-semibold text-product-accent hover:underline">
                    <AppIcon name="globe" :size="16" />{{ website.replace(/^https?:\/\//, '') }}
                </a>

                <div class="mt-10 border-t border-line pt-8">
                    <h3 class="font-semibold">What happens next</h3>
                    <CheckList class="mt-4" :items="next" />
                </div>
            </div>

            <div class="space-y-6">
                <div class="mk-hero relative isolate overflow-hidden rounded-3xl p-8 text-white shadow-xl">
                    <div class="mk-grid pointer-events-none absolute inset-0 -z-10" aria-hidden="true" />
                    <span class="grid size-11 place-items-center rounded-xl bg-white/10 text-product-teal"><AppIcon name="user" :size="22" /></span>
                    <h2 class="mt-5 text-xl font-bold">Already using {{ product.name }}?</h2>
                    <p class="mt-2 text-sm leading-relaxed text-white/75">Sign in with the account your administrator gave you.</p>
                    <div class="mt-6 flex flex-wrap gap-3">
                        <Link href="/login" class="btn btn-product">Staff sign-in</Link>
                        <Link href="/password/reset" class="btn btn-on-dark">Reset password</Link>
                    </div>
                </div>
                <div v-if="feature('customer_portal')" class="rounded-3xl border border-line bg-surface p-8 shadow-sm">
                    <span class="grid size-11 place-items-center rounded-xl bg-product-accent/10 text-product-accent"><AppIcon name="bag" :size="22" /></span>
                    <h2 class="mt-5 text-xl font-bold">Buying from us?</h2>
                    <p class="mt-2 text-sm leading-relaxed text-ink-muted">Customers order, follow deliveries and see invoices in the customer portal.</p>
                    <Link href="/portal" class="btn btn-secondary mt-6">Open the customer portal<AppIcon name="arrow-right" :size="16" /></Link>
                </div>
                <div class="rounded-3xl border border-line bg-surface-muted p-8">
                    <h2 class="font-semibold">Before the demo</h2>
                    <p class="mt-2 text-sm leading-relaxed text-ink-muted">
                        Have a look at what {{ product.name }} does, and the controls behind it.
                    </p>
                    <div class="mt-4 flex flex-wrap gap-x-5 gap-y-2 text-sm font-semibold text-product-accent">
                        <Link href="/features" class="hover:underline">Features</Link>
                        <Link href="/security" class="hover:underline">Security</Link>
                        <Link href="/onboarding" class="hover:underline">Onboarding</Link>
                    </div>
                </div>
            </div>
        </section>
    </div>
</template>
