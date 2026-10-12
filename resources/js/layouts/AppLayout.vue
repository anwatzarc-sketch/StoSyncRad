<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppIcon from '@/components/ui/AppIcon.vue';
import ToastHost from '@/components/ui/ToastHost.vue';
import StepUpDialog from '@/components/StepUpDialog.vue';
import ProductFooter from '@/components/ProductFooter.vue';
import ProductWordmark from '@/components/ProductWordmark.vue';
import { useShared } from '@/composables/useShared';
import { get, post } from '@/lib/api';
import { applyUpdate, pwaState } from '@/lib/pwa';
import { notify } from '@/lib/toast';

/**
 * App shell (Chapter 12 §12.4). Desktop: collapsible sidebar with the server-filtered sections.
 * Mobile: top bar + bottom navigation (Overview, Stock, Work, Reports, More). The product identity
 * (wordmark, footer) and the customer (company name, theme) come from the shared `brand` prop.
 * Nothing here is customer-specific.
 */
const { brand, auth, nav, props } = useShared();
const page = usePage();
const drawer = ref(false);
const collapsed = ref<Record<string, boolean>>({});
const unread = ref(0);
const userMenu = ref(false);

const current = computed(() => page.url.split('?')[0]);
const isActive = (href: string) => (href === '/' ? current.value === '/' : current.value === href || current.value.startsWith(href + '/'));
const allItems = computed(() => nav.value.flatMap((s) => s.items));
const has = (href: string) => allItems.value.some((i) => i.href === href);
const workHref = computed(() => nav.value.find((s) => ['operations', 'receiving', 'transfers', 'sales', 'stocktaking', 'procurement'].includes(s.key))?.items[0]?.href ?? null);
const bottom = computed(() => [
    { label: 'Overview', href: '/', icon: 'home' },
    has('/stock') ? { label: 'Stock', href: '/stock', icon: 'boxes' } : null,
    workHref.value ? { label: 'Work', href: workHref.value, icon: 'clipboard' } : null,
    has('/reports') ? { label: 'Reports', href: '/reports', icon: 'chart' } : null,
].filter((x): x is { label: string; href: string; icon: string } => x !== null));

onMounted(async () => {
    if (props.value.flash?.success) {
        notify(props.value.flash.success);
    }
    try {
        unread.value = (await get<{ data: { unread_notifications: number } }>('/api/v1/me')).data.unread_notifications ?? 0;
    } catch {
        unread.value = 0;
    }
});

async function logout() {
    try {
        await post('/api/v1/auth/logout');
    } finally {
        window.location.assign('/login');
    }
}

function update() {
    if (!applyUpdate()) {
        notify('Finish the current posting first; the update will wait.', 'info');
    }
}
</script>

<template>
    <div class="min-h-dvh bg-surface-muted text-ink">
        <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50 focus:rounded-brand focus:bg-surface focus:p-3">
            Skip to content
        </a>

        <!-- Sidebar (desktop) / drawer (mobile) -->
        <aside
            :class="['fixed inset-y-0 left-0 z-40 flex w-72 flex-col bg-nav text-nav-ink transition-transform lg:translate-x-0',
                     drawer ? 'translate-x-0' : '-translate-x-full']"
            aria-label="Main navigation"
        >
            <div class="flex h-16 items-center gap-3 px-4">
                <Link href="/" class="min-w-0" :aria-label="`${brand.product.name} home`"><ProductWordmark on-dark show-company /></Link>
                <button type="button" class="ml-auto rounded p-2 lg:hidden" aria-label="Close menu" @click="drawer = false"><AppIcon name="x" /></button>
            </div>
            <nav class="flex-1 overflow-y-auto px-2 pb-6">
                <div v-for="section in nav" :key="section.key" class="mt-2">
                    <button
                        type="button"
                        class="flex min-h-10 w-full items-center gap-2 rounded px-3 text-xs font-semibold uppercase tracking-wide opacity-75 hover:opacity-100"
                        :aria-expanded="!collapsed[section.key]"
                        @click="collapsed[section.key] = !collapsed[section.key]"
                    >
                        {{ section.label }}
                        <AppIcon :name="collapsed[section.key] ? 'chevron-right' : 'chevron-down'" :size="14" class="ml-auto" />
                    </button>
                    <ul v-show="!collapsed[section.key]">
                        <li v-for="item in section.items" :key="item.href">
                            <Link
                                :href="item.href"
                                :class="['flex min-h-11 items-center gap-3 rounded-brand px-3 text-sm',
                                         isActive(item.href) ? 'bg-white/15 font-semibold' : 'hover:bg-white/10']"
                                :aria-current="isActive(item.href) ? 'page' : undefined"
                            >
                                <AppIcon :name="item.icon" :size="18" />
                                {{ item.label }}
                            </Link>
                        </li>
                    </ul>
                </div>
            </nav>
            <p class="px-4 pb-4 text-[11px] opacity-60">{{ brand.product.name }}<template v-if="brand.product.vendor"> · by {{ brand.product.vendor }}</template></p>
        </aside>
        <div v-if="drawer" class="fixed inset-0 z-30 bg-black/40 lg:hidden" aria-hidden="true" @click="drawer = false" />

        <div class="lg:pl-72">
            <!-- Top bar -->
            <header class="sticky top-0 z-20 flex h-16 items-center gap-2 border-b border-line bg-surface px-3 md:px-6">
                <button type="button" class="btn btn-ghost px-2 lg:hidden" aria-label="Open menu" @click="drawer = true"><AppIcon name="menu" /></button>
                <Link href="/" class="min-w-0 lg:hidden" :aria-label="`${brand.product.name} home`"><ProductWordmark size="sm" /></Link>
                <span v-if="!pwaState.online" class="ml-2 inline-flex items-center gap-1 rounded-full bg-warning/15 px-2 py-1 text-xs font-semibold text-warning">
                    <AppIcon name="wifi-off" :size="14" /> Offline
                </span>
                <div class="ml-auto flex items-center gap-1">
                    <a href="/scan" class="btn btn-ghost px-2" aria-label="Scan"><AppIcon name="scan" /></a>
                    <a href="/notifications" class="btn btn-ghost relative px-2" :aria-label="`Notifications (${unread} unread)`">
                        <AppIcon name="bell" />
                        <span v-if="unread > 0" class="absolute right-1 top-1 min-w-5 rounded-full bg-danger px-1 text-center text-[11px] font-bold text-white">{{ unread > 99 ? '99+' : unread }}</span>
                    </a>
                    <div class="relative">
                        <button type="button" class="btn btn-ghost px-2" :aria-expanded="userMenu" aria-haspopup="menu" @click="userMenu = !userMenu">
                            <AppIcon name="user" />
                            <span class="hidden max-w-40 truncate text-sm md:inline">{{ auth?.account.display_name }}</span>
                        </button>
                        <div v-if="userMenu" class="absolute right-0 mt-1 w-56 rounded-brand border border-line bg-surface py-1 shadow-lg" role="menu">
                            <a href="/account" class="flex min-h-11 items-center gap-2 px-4 text-sm hover:bg-surface-muted" role="menuitem"><AppIcon name="shield" :size="16" /> Account & security</a>
                            <button type="button" class="flex min-h-11 w-full items-center gap-2 px-4 text-left text-sm hover:bg-surface-muted" role="menuitem" @click="logout">
                                <AppIcon name="logout" :size="16" /> Sign out
                            </button>
                        </div>
                    </div>
                </div>
            </header>

            <div v-if="!pwaState.online" class="border-b border-warning/30 bg-warning/10 px-4 py-2 text-sm text-ink" role="status">
                You are offline. Stock, postings and reports need a connection; nothing is saved or sent later.
            </div>
            <div v-if="pwaState.updateReady" class="flex items-center justify-between gap-3 border-b border-info/30 bg-info/10 px-4 py-2 text-sm" role="status">
                A new version is ready.
                <button type="button" class="btn btn-secondary min-h-9" @click="update">Update now</button>
            </div>

            <main id="main" class="mx-auto max-w-7xl px-4 pb-28 pt-5 md:px-6 lg:pb-10">
                <slot />
                <ProductFooter class="mt-12 border-t border-line pt-4" />
            </main>
        </div>

        <!-- Bottom navigation (mobile) -->
        <nav class="fixed inset-x-0 bottom-0 z-20 border-t border-line bg-surface pb-[env(safe-area-inset-bottom)] lg:hidden" aria-label="Quick navigation">
            <ul class="grid" :style="{ gridTemplateColumns: `repeat(${bottom.length + 1}, minmax(0, 1fr))` }">
                <li v-for="item in bottom" :key="item.label">
                    <Link
                        :href="item.href"
                        :class="['flex min-h-14 flex-col items-center justify-center gap-0.5 text-xs', isActive(item.href) ? 'font-semibold text-brand' : 'text-ink-muted']"
                        :aria-current="isActive(item.href) ? 'page' : undefined"
                    >
                        <AppIcon :name="item.icon" />
                        {{ item.label }}
                    </Link>
                </li>
                <li>
                    <button type="button" class="flex min-h-14 w-full flex-col items-center justify-center gap-0.5 text-xs text-ink-muted" @click="drawer = true">
                        <AppIcon name="menu" /> More
                    </button>
                </li>
            </ul>
        </nav>

        <ToastHost />
        <StepUpDialog />
    </div>
</template>
