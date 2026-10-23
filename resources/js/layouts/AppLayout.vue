<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
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
import type { NavItem } from '@/types/shared';

/**
 * App shell (Chapter 12 §12.4). Two areas from the server-filtered navigation: daily work — a short
 * sidebar with Home, Stock, one entry per workflow and Reports — and Administration, the control
 * plane (master data, organization, people & access, platform, oversight), entered deliberately and
 * shown with its own grouped sidebar. Mobile: top bar + bottom navigation (Home, Stock, Work,
 * Reports, More). The product identity and the customer (company name, theme) come from `brand`.
 */
const { brand, auth, nav, props } = useShared();
const page = usePage();
const drawer = ref(false);
const collapsed = ref<Record<string, boolean>>({});
const unread = ref(0);
const userMenu = ref(false);

const current = computed(() => page.url.split('?')[0]);
const matches = (href: string) => (href === '/' ? current.value === '/' : current.value === href || current.value.startsWith(href + '/'));
const isActive = (item: NavItem) => matches(item.href) || (item.match ?? []).some(matches);

const workItems = computed(() => nav.value.find((s) => s.area === 'work')?.items ?? []);
const adminSections = computed(() => nav.value.filter((s) => s.area === 'admin'));
const hasAdmin = computed(() => adminSections.value.length > 0);
const inAdmin = computed(() => current.value === '/admin' || current.value.startsWith('/admin/')
    || adminSections.value.some((s) => s.items.some(isActive)));
const hubs = computed(() => workItems.value.filter((i) => i.href.startsWith('/work/')));
const workActive = computed(() => matches('/work') || hubs.value.some(isActive));

const item = (href: string) => workItems.value.find((i) => i.href === href || (i.match ?? []).includes(href));
const bottom = computed(() => [
    { label: 'Home', href: '/', icon: 'home', active: matches('/') },
    item('/stock') ? { label: 'Stock', href: item('/stock')!.href, icon: 'boxes', active: isActive(item('/stock')!) } : null,
    hubs.value.length ? { label: 'Work', href: '/work', icon: 'clipboard', active: workActive.value } : null,
    item('/reports') ? { label: 'Reports', href: '/reports', icon: 'chart', active: isActive(item('/reports')!) } : null,
].filter((x): x is { label: string; href: string; icon: string; active: boolean } => x !== null));

watch(() => page.url, () => {
    drawer.value = false;
    userMenu.value = false;
});

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
            :aria-label="inAdmin ? 'Administration navigation' : 'Main navigation'"
        >
            <div class="flex h-16 items-center gap-3 px-4">
                <Link href="/" class="min-w-0" :aria-label="`${brand.product.name} home`"><ProductWordmark on-dark show-company /></Link>
                <button type="button" class="ml-auto rounded p-2 lg:hidden" aria-label="Close menu" @click="drawer = false"><AppIcon name="x" /></button>
            </div>

            <!-- Daily work -->
            <nav v-if="!inAdmin" class="flex-1 overflow-y-auto px-2 pb-6">
                <ul class="mt-2 space-y-0.5">
                    <li v-for="entry in workItems" :key="entry.href">
                        <Link
                            :href="entry.href"
                            :class="['flex min-h-11 items-center gap-3 rounded-brand px-3 text-sm',
                                     isActive(entry) ? 'bg-white/15 font-semibold' : 'hover:bg-white/10']"
                            :aria-current="isActive(entry) ? 'page' : undefined"
                        >
                            <AppIcon :name="entry.icon" :size="18" />
                            {{ entry.label }}
                        </Link>
                    </li>
                </ul>
            </nav>

            <!-- Administration (control plane) -->
            <nav v-else class="flex-1 overflow-y-auto px-2 pb-6">
                <Link href="/" class="mt-2 flex min-h-10 items-center gap-2 rounded-brand px-3 text-sm opacity-80 hover:bg-white/10 hover:opacity-100">
                    <AppIcon name="chevron-left" :size="16" /> Back to operations
                </Link>
                <Link
                    href="/admin"
                    :class="['mt-1 flex min-h-11 items-center gap-3 rounded-brand px-3 text-sm font-semibold', current === '/admin' ? 'bg-white/15' : 'hover:bg-white/10']"
                >
                    <AppIcon name="settings" :size="18" /> Administration
                </Link>
                <div v-for="section in adminSections" :key="section.key" class="mt-2">
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
                        <li v-for="entry in section.items" :key="entry.href">
                            <Link
                                :href="entry.href"
                                :class="['flex min-h-11 items-center gap-3 rounded-brand px-3 text-sm',
                                         isActive(entry) ? 'bg-white/15 font-semibold' : 'hover:bg-white/10']"
                                :aria-current="isActive(entry) ? 'page' : undefined"
                            >
                                <AppIcon :name="entry.icon" :size="18" />
                                {{ entry.label }}
                            </Link>
                        </li>
                    </ul>
                </div>
            </nav>

            <div class="border-t border-white/10 px-2 py-3">
                <Link
                    v-if="hasAdmin && !inAdmin"
                    href="/admin"
                    class="flex min-h-11 items-center gap-3 rounded-brand px-3 text-sm opacity-80 hover:bg-white/10 hover:opacity-100"
                >
                    <AppIcon name="settings" :size="18" /> Administration
                </Link>
                <p class="px-3 pt-1 text-[11px] opacity-60">{{ brand.product.name }}<template v-if="brand.product.vendor"> · by {{ brand.product.vendor }}</template></p>
            </div>
        </aside>
        <div v-if="drawer" class="fixed inset-0 z-30 bg-black/40 lg:hidden" aria-hidden="true" @click="drawer = false" />

        <div class="lg:pl-72">
            <!-- Top bar -->
            <header class="sticky top-0 z-20 flex h-16 items-center gap-2 border-b border-line bg-surface px-3 md:px-6">
                <button type="button" class="btn btn-ghost px-2 lg:hidden" aria-label="Open menu" @click="drawer = true"><AppIcon name="menu" /></button>
                <Link href="/" class="min-w-0 lg:hidden" :aria-label="`${brand.product.name} home`"><ProductWordmark size="sm" /></Link>
                <span v-if="inAdmin" class="hidden items-center gap-1 rounded-full bg-surface-muted px-3 py-1 text-xs font-semibold text-ink-muted sm:inline-flex">
                    <AppIcon name="settings" :size="14" /> Administration
                </span>
                <span v-if="!pwaState.online" class="ml-2 inline-flex items-center gap-1 rounded-full bg-warning/15 px-2 py-1 text-xs font-semibold text-warning">
                    <AppIcon name="wifi-off" :size="14" /> Offline
                </span>
                <div class="ml-auto flex items-center gap-1">
                    <Link href="/scan" class="btn btn-ghost px-2" aria-label="Scan"><AppIcon name="scan" /></Link>
                    <Link href="/notifications" class="btn btn-ghost relative px-2" :aria-label="`Notifications (${unread} unread)`">
                        <AppIcon name="bell" />
                        <span v-if="unread > 0" class="absolute right-1 top-1 min-w-5 rounded-full bg-danger px-1 text-center text-[11px] font-bold text-white">{{ unread > 99 ? '99+' : unread }}</span>
                    </Link>
                    <div class="relative">
                        <button type="button" class="btn btn-ghost px-2" :aria-expanded="userMenu" aria-haspopup="menu" @click="userMenu = !userMenu">
                            <AppIcon name="user" />
                            <span class="hidden max-w-40 truncate text-sm md:inline">{{ auth?.account.display_name }}</span>
                        </button>
                        <div v-if="userMenu" class="absolute right-0 mt-1 w-56 rounded-brand border border-line bg-surface py-1 shadow-lg" role="menu">
                            <Link href="/account" class="flex min-h-11 items-center gap-2 px-4 text-sm hover:bg-surface-muted" role="menuitem"><AppIcon name="shield" :size="16" /> Account & security</Link>
                            <Link v-if="hasAdmin" :href="inAdmin ? '/' : '/admin'" class="flex min-h-11 items-center gap-2 px-4 text-sm hover:bg-surface-muted" role="menuitem">
                                <AppIcon :name="inAdmin ? 'home' : 'settings'" :size="16" /> {{ inAdmin ? 'Back to operations' : 'Administration' }}
                            </Link>
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
                <li v-for="entry in bottom" :key="entry.label">
                    <Link
                        :href="entry.href"
                        :class="['flex min-h-14 flex-col items-center justify-center gap-0.5 text-xs', entry.active ? 'font-semibold text-brand' : 'text-ink-muted']"
                        :aria-current="entry.active ? 'page' : undefined"
                    >
                        <AppIcon :name="entry.icon" />
                        {{ entry.label }}
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
