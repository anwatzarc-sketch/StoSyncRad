<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppIcon from '@/components/ui/AppIcon.vue';
import { useShared } from '@/composables/useShared';
import type { NavArea } from '@/types/shared';

/** Tab strip of one place of the daily navigation (Stock, Reports): its server-filtered pages. */
const props = defineProps<{ area: Extract<NavArea, 'stock' | 'reports'> }>();

const { nav } = useShared();
const page = usePage();
const items = computed(() => nav.value.find((s) => s.area === props.area)?.items ?? []);
const current = computed(() => page.url.split('?')[0]);
const active = (href: string) => current.value === href || current.value.startsWith(href + '/');
</script>

<template>
    <nav v-if="items.length > 1" class="-mx-4 mb-5 overflow-x-auto border-b border-line px-4 md:mx-0 md:px-0" :aria-label="area === 'stock' ? 'Stock' : 'Reports'">
        <ul class="flex gap-1">
            <li v-for="i in items" :key="i.href">
                <Link
                    :href="i.href"
                    :class="['-mb-px flex min-h-11 items-center gap-2 border-b-2 px-3 text-sm whitespace-nowrap',
                             active(i.href) ? 'border-brand font-semibold text-ink' : 'border-transparent text-ink-muted hover:text-ink']"
                    :aria-current="active(i.href) ? 'page' : undefined"
                >
                    <AppIcon :name="i.icon" :size="16" />
                    {{ i.label }}
                </Link>
            </li>
        </ul>
    </nav>
</template>
