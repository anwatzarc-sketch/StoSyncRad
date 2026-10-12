<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppIcon from '@/components/ui/AppIcon.vue';
import ToastHost from '@/components/ui/ToastHost.vue';
import ProductFooter from '@/components/ProductFooter.vue';
import ProductWordmark from '@/components/ProductWordmark.vue';
import { useShared } from '@/composables/useShared';
import { post } from '@/lib/api';
import { canDo, cart, loadProfile, profile } from '@/lib/portal';

/**
 * Customer Portal shell (CP-1.1 section 8): its own header, navigation and footer — none of the staff
 * application's menus or controls. Mobile first: a bottom bar on phones, a top bar on larger screens.
 */
const { brand } = useShared();
const page = usePage();
const accountMenu = ref(false);

const nav = [
    { label: 'Home', href: '/portal/dashboard', icon: 'home' },
    { label: 'Products', href: '/portal/catalog', icon: 'boxes' },
    { label: 'Quotations', href: '/portal/quotations', icon: 'clipboard' },
    { label: 'Orders', href: '/portal/orders', icon: 'truck' },
    { label: 'Invoices', href: '/portal/invoices', icon: 'file' },
    { label: 'Payments', href: '/portal/payments', icon: 'coins' },
];
const current = computed(() => page.url.split('?')[0]);
const isActive = (href: string) => current.value === href || current.value.startsWith(href + '/');

onMounted(() => void loadProfile().catch(() => undefined));

async function signOut() {
    try {
        await post('/api/v1/auth/logout');
    } finally {
        window.location.assign('/portal');
    }
}
</script>

<template>
    <div class="flex min-h-dvh flex-col bg-surface-muted text-ink">
        <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50 focus:rounded-brand focus:bg-surface focus:p-3">Skip to content</a>
        <header class="sticky top-0 z-20 border-b border-line bg-surface">
            <div class="mx-auto flex h-16 max-w-6xl items-center gap-3 px-4">
                <Link href="/portal/dashboard" class="min-w-0" :aria-label="`${brand.product.name} customer portal home`">
                    <ProductWordmark size="sm" show-company />
                </Link>
                <nav class="ml-6 hidden items-center gap-1 lg:flex" aria-label="Customer portal">
                    <Link
                        v-for="item in nav"
                        :key="item.href"
                        :href="item.href"
                        :class="['rounded-brand px-3 py-2 text-sm', isActive(item.href) ? 'bg-accent/10 font-semibold text-accent' : 'hover:bg-surface-muted']"
                        :aria-current="isActive(item.href) ? 'page' : undefined"
                    >
                        {{ item.label }}
                    </Link>
                </nav>
                <div class="ml-auto flex items-center gap-1">
                    <Link href="/portal/cart" class="btn btn-ghost relative px-2" :aria-label="`Cart (${cart.lines.length} products)`">
                        <AppIcon name="cart" />
                        <span v-if="cart.lines.length" class="absolute right-0.5 top-0.5 min-w-5 rounded-full bg-accent px-1 text-center text-[11px] font-bold text-accent-contrast">
                            {{ cart.lines.length }}
                        </span>
                    </Link>
                    <div class="relative">
                        <button type="button" class="btn btn-ghost px-2" :aria-expanded="accountMenu" aria-haspopup="menu" @click="accountMenu = !accountMenu">
                            <AppIcon name="user" />
                            <span class="hidden max-w-48 truncate text-sm md:inline">{{ profile?.customer.name ?? 'Account' }}</span>
                        </button>
                        <div v-if="accountMenu" class="absolute right-0 mt-1 w-64 rounded-brand border border-line bg-surface py-1 shadow-lg" role="menu">
                            <p v-if="profile" class="border-b border-line px-4 py-2 text-xs text-ink-muted">
                                Signed in as <strong class="text-ink">{{ profile.account.name }}</strong><br>{{ profile.customer.name }}
                            </p>
                            <Link href="/portal/account" class="flex min-h-11 items-center gap-2 px-4 text-sm hover:bg-surface-muted" role="menuitem">
                                <AppIcon name="shield" :size="16" /> Account &amp; security
                            </Link>
                            <Link v-if="canDo('customer.member.read')" href="/portal/team" class="flex min-h-11 items-center gap-2 px-4 text-sm hover:bg-surface-muted" role="menuitem">
                                <AppIcon name="users" :size="16" /> Team
                            </Link>
                            <button type="button" class="flex min-h-11 w-full items-center gap-2 px-4 text-left text-sm hover:bg-surface-muted" role="menuitem" @click="signOut">
                                <AppIcon name="logout" :size="16" /> Sign out
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <main id="main" class="mx-auto w-full max-w-6xl flex-1 px-4 pb-28 pt-5 lg:pb-10">
            <slot />
            <ProductFooter class="mt-12 border-t border-line pt-4" />
        </main>

        <nav class="fixed inset-x-0 bottom-0 z-20 border-t border-line bg-surface pb-[env(safe-area-inset-bottom)] lg:hidden" aria-label="Customer portal">
            <ul class="grid grid-cols-6">
                <li v-for="item in nav" :key="item.href">
                    <Link
                        :href="item.href"
                        :class="['flex min-h-14 flex-col items-center justify-center gap-0.5 text-[11px]', isActive(item.href) ? 'font-semibold text-accent' : 'text-ink-muted']"
                        :aria-current="isActive(item.href) ? 'page' : undefined"
                    >
                        <AppIcon :name="item.icon" :size="20" />
                        {{ item.label }}
                    </Link>
                </li>
            </ul>
        </nav>
        <ToastHost />
    </div>
</template>
