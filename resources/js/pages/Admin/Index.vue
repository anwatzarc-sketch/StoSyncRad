<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import AppIcon from '@/components/ui/AppIcon.vue';
import { useShared } from '@/composables/useShared';

/**
 * Administration — the control plane: master data, organization, people & access, platform settings,
 * go-live data and oversight. Everything here changes how daily work behaves, so it lives apart from
 * the daily navigation. Entries are the server-filtered `admin` navigation sections.
 */
const { nav } = useShared();
const sections = computed(() => nav.value.filter((s) => s.area === 'admin'));

const ABOUT: Record<string, string> = {
    catalog: 'Items, categories, units and the rules attached to them.',
    partners: 'Suppliers, customers and carriers.',
    organization: 'Branches, warehouses, storage locations and put-away rules.',
    access: 'Accounts, roles, who may approve what, and API access.',
    platform: 'Company profile, settings, branding, templates and numbering.',
    data: 'Bulk imports and opening balances for going live.',
    oversight: 'Audit trail, security events, system health and stock reconciliation.',
};
</script>

<template>
    <div>
        <PageHeader title="Administration" subtitle="Set up and look after the system. Daily work happens in the main navigation." />
        <p v-if="!sections.length" class="card p-6 text-sm text-ink-muted">You have no administration permissions.</p>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <section v-for="s in sections" :key="s.key" class="card p-5" :aria-labelledby="`admin-${s.key}`">
                <div class="mb-3 flex items-center gap-3">
                    <span class="grid size-10 place-items-center rounded-lg bg-brand/10 text-brand"><AppIcon :name="s.icon" /></span>
                    <div>
                        <h2 :id="`admin-${s.key}`" class="font-semibold">{{ s.label }}</h2>
                        <p v-if="ABOUT[s.key]" class="text-xs text-ink-muted">{{ ABOUT[s.key] }}</p>
                    </div>
                </div>
                <ul class="divide-y divide-line">
                    <li v-for="i in s.items" :key="i.href">
                        <Link :href="i.href" class="flex min-h-11 items-center gap-3 text-sm hover:text-brand">
                            <AppIcon :name="i.icon" :size="16" class="text-ink-muted" />
                            {{ i.label }}
                            <AppIcon name="chevron-right" :size="16" class="ml-auto text-ink-muted" />
                        </Link>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
