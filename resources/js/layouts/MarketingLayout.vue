<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppIcon from '@/components/ui/AppIcon.vue';
import ProductWordmark from '@/components/ProductWordmark.vue';
import ToastHost from '@/components/ui/ToastHost.vue';
import { useShared } from '@/composables/useShared';

/** Public product website (pages/Marketing): product navigation, sign-in and the product footer. */
const page = usePage();
const { brand, auth, feature } = useShared();
const product = computed(() => brand.value.product);
const menuOpen = ref(false);
const year = new Date().getFullYear();

const links = [
    { href: '/features', label: 'Features' },
    { href: '/solutions', label: 'Solutions' },
    { href: '/security', label: 'Security' },
    { href: '/onboarding', label: 'Onboarding' },
    { href: '/contact', label: 'Contact' },
];
const isActive = (href: string) => page.url === href || page.url.startsWith(`${href}#`) || page.url.startsWith(`${href}?`);

watch(() => page.url, () => (menuOpen.value = false));
</script>

<template>
    <div class="flex min-h-dvh flex-col bg-surface text-ink">
        <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-brand focus:bg-surface focus:px-4 focus:py-2">Skip to content</a>
        <header class="sticky top-0 z-40 border-b border-line/70 bg-surface/85 backdrop-blur supports-[backdrop-filter]:bg-surface/70">
            <div class="mk-container flex h-16 items-center gap-6">
                <Link href="/" class="min-w-0" :aria-label="`${product.name} home`"><ProductWordmark size="sm" /></Link>
                <nav class="hidden items-center gap-1 lg:flex" aria-label="Product">
                    <Link
                        v-for="l in links"
                        :key="l.href"
                        :href="l.href"
                        :class="['rounded-brand px-3 py-2 text-sm font-medium transition', isActive(l.href) ? 'text-product-accent' : 'text-ink-muted hover:text-ink']"
                        :aria-current="isActive(l.href) ? 'page' : undefined"
                    >
                        {{ l.label }}
                    </Link>
                </nav>
                <div class="ml-auto hidden items-center gap-2 sm:flex">
                    <template v-if="auth">
                        <Link href="/" class="btn btn-product">Open {{ product.name }}<AppIcon name="arrow-right" :size="16" /></Link>
                    </template>
                    <template v-else>
                        <Link href="/login" class="btn btn-ghost">Sign in</Link>
                        <Link href="/contact" class="btn btn-product">Book a demo</Link>
                    </template>
                </div>
                <button
                    type="button"
                    class="btn btn-ghost ml-auto px-3 sm:ml-0 lg:hidden"
                    :aria-expanded="menuOpen"
                    aria-controls="marketing-menu"
                    @click="menuOpen = !menuOpen"
                >
                    <AppIcon :name="menuOpen ? 'x' : 'menu'" :label="menuOpen ? 'Close menu' : 'Open menu'" />
                </button>
            </div>
            <nav v-show="menuOpen" id="marketing-menu" class="border-t border-line bg-surface lg:hidden" aria-label="Product">
                <div class="mk-container grid gap-1 py-3">
                    <Link
                        v-for="l in links"
                        :key="l.href"
                        :href="l.href"
                        :class="['rounded-brand px-3 py-3 text-base font-medium', isActive(l.href) ? 'bg-surface-muted text-product-accent' : 'text-ink hover:bg-surface-muted']"
                    >
                        {{ l.label }}
                    </Link>
                    <div class="mt-2 grid gap-2 border-t border-line pt-3 sm:hidden">
                        <Link v-if="auth" href="/" class="btn btn-product">Open {{ product.name }}</Link>
                        <template v-else>
                            <Link href="/contact" class="btn btn-product">Book a demo</Link>
                            <Link href="/login" class="btn btn-secondary">Sign in</Link>
                        </template>
                    </div>
                </div>
            </nav>
        </header>

        <main id="main" class="flex-1">
            <slot />
        </main>

        <footer class="mk-hero relative overflow-hidden text-white/80">
            <div class="mk-container grid gap-10 py-16 md:grid-cols-[1.4fr_1fr_1fr_1fr]">
                <div class="max-w-sm">
                    <ProductWordmark on-dark />
                    <p class="mt-4 text-sm leading-relaxed text-white/70">
                        {{ product.tagline }} for companies that run many branches and warehouses — one ledger, lot and expiry control, and reports you can trust.
                    </p>
                </div>
                <nav aria-label="Product pages">
                    <h2 class="text-sm font-semibold text-white">Product</h2>
                    <ul class="mt-4 space-y-3 text-sm">
                        <li><Link href="/features" class="hover:text-white">Features</Link></li>
                        <li><Link href="/solutions" class="hover:text-white">Solutions</Link></li>
                        <li><Link href="/security" class="hover:text-white">Security</Link></li>
                        <li><Link href="/onboarding" class="hover:text-white">Onboarding</Link></li>
                    </ul>
                </nav>
                <nav aria-label="Company">
                    <h2 class="text-sm font-semibold text-white">Company</h2>
                    <ul class="mt-4 space-y-3 text-sm">
                        <li><Link href="/contact" class="hover:text-white">Contact &amp; demo</Link></li>
                        <li v-if="product.vendor_url"><a :href="product.vendor_url" class="hover:text-white" target="_blank" rel="noopener">{{ product.vendor }}</a></li>
                        <li v-if="product.vendor_email"><a :href="`mailto:${product.vendor_email}`" class="hover:text-white">{{ product.vendor_email }}</a></li>
                    </ul>
                </nav>
                <nav aria-label="Access">
                    <h2 class="text-sm font-semibold text-white">Access</h2>
                    <ul class="mt-4 space-y-3 text-sm">
                        <li><Link href="/login" class="hover:text-white">Staff sign-in</Link></li>
                        <li v-if="feature('customer_portal')"><Link href="/portal" class="hover:text-white">Customer portal</Link></li>
                        <li><Link href="/password/reset" class="hover:text-white">Reset password</Link></li>
                    </ul>
                </nav>
            </div>
            <div class="border-t border-white/10">
                <p class="mk-container flex flex-wrap justify-between gap-2 py-6 text-xs text-white/60">
                    <span>© {{ year }} {{ product.vendor ?? product.name }}. All rights reserved.</span>
                    <span>{{ product.name }} — {{ product.tagline }}</span>
                </p>
            </div>
        </footer>
        <ToastHost />
    </div>
</template>
