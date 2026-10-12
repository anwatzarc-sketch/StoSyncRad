<script setup lang="ts">
import { computed } from 'vue';
import { useShared } from '@/composables/useShared';

/**
 * Product mark + two-tone wordmark (config/product.php — the same for every customer), optionally
 * followed by the customer's company name. `onDark` is for the navigation rail: the mark sits on a
 * white tile and the first wordmark part turns white.
 */
const props = withDefaults(defineProps<{ onDark?: boolean; showCompany?: boolean; size?: 'sm' | 'md' }>(), {
    onDark: false,
    showCompany: false,
    size: 'md',
});

const { brand } = useShared();
const product = computed(() => brand.value.product);
const parts = computed(() => (product.value.wordmark.length > 0 ? product.value.wordmark : [product.value.name]));
const tile = computed(() => (props.size === 'sm' ? 'h-8 w-8' : 'h-9 w-9'));
</script>

<template>
    <span class="flex min-w-0 items-center gap-2.5">
        <span :class="['grid shrink-0 place-items-center rounded-lg', tile, onDark ? 'bg-white p-1' : '']">
            <img :src="product.assets.logo_mark" alt="" class="h-full w-full object-contain">
        </span>
        <span class="min-w-0 leading-tight">
            <span :class="['block truncate font-bold tracking-tight', size === 'sm' ? 'text-base' : 'text-lg']" :aria-label="product.name">
                <span aria-hidden="true" :class="onDark ? 'text-white' : 'text-product-ink'">{{ parts[0] }}</span><span
                    v-if="parts[1]" aria-hidden="true" :class="onDark ? 'text-product-teal' : 'text-product-accent'">{{ parts[1] }}</span>
            </span>
            <span v-if="showCompany && brand.company_name" class="block truncate text-xs font-medium opacity-80">{{ brand.company_name }}</span>
        </span>
    </span>
</template>
