<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppIcon from '@/components/ui/AppIcon.vue';
import { parentOf, type NavTarget } from '@/lib/navigation';

/**
 * Back and Home for pages outside the app shell (sign-in, public portal pages, the product website).
 * Back is the page's logical parent (lib/navigation) — the area's own home when that parent is the
 * site root (signed out without a product website, `/` is the sign-in page). When Back is Home itself,
 * one "Back to Home" link serves as both. Nothing shows on the home page itself.
 */
const props = withDefaults(defineProps<{ home: NavTarget; onDark?: boolean }>(), { onDark: false });

const page = usePage();
const path = computed(() => page.url.split('?')[0]);
const back = computed(() => {
    const parent = parentOf(path.value);
    const target = parent?.href === '/' ? props.home : parent;

    return target && target.href !== path.value ? target : null;
});
const showHome = computed(() => props.home.href !== path.value && back.value?.href !== props.home.href);
const tone = computed(() => (props.onDark ? 'text-white/70 hover:text-white' : 'text-ink-muted hover:text-ink'));
</script>

<template>
    <nav v-if="back || showHome" class="flex flex-wrap items-center gap-x-3 text-sm" aria-label="Back and home">
        <Link v-if="back" :href="back.href" :class="['inline-flex min-h-9 items-center gap-1', tone]">
            <AppIcon name="chevron-left" :size="16" /> Back to {{ back.label }}
        </Link>
        <Link v-if="showHome" :href="home.href" :class="['inline-flex min-h-9 items-center gap-1', tone]">
            <AppIcon name="home" :size="15" /> {{ home.label }}
        </Link>
    </nav>
</template>
