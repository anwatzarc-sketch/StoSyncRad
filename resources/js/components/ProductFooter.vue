<script setup lang="ts">
import { computed } from 'vue';
import { useShared } from '@/composables/useShared';

/** Footer line: the customer (company + legal footer) and the product (name, tagline, vendor). */
withDefaults(defineProps<{ centered?: boolean }>(), { centered: false });

const { brand } = useShared();
const product = computed(() => brand.value.product);
const year = new Date().getFullYear();
</script>

<template>
    <footer :class="['space-y-1 text-xs text-ink-muted', centered ? 'text-center' : '']">
        <p v-if="brand.company_name || brand.legal_footer">
            <span v-if="brand.company_name">© {{ year }} {{ brand.company_name }}</span>
            <span v-if="brand.company_name && brand.legal_footer && brand.legal_footer !== brand.company_name"> · </span>
            <span v-if="brand.legal_footer && brand.legal_footer !== brand.company_name">{{ brand.legal_footer }}</span>
        </p>
        <p>
            <strong class="font-semibold"><span class="text-product-ink">{{ product.wordmark[0] ?? product.name }}</span><span
                class="text-product-accent">{{ product.wordmark[1] ?? '' }}</span></strong>
            <span v-if="product.tagline"> — {{ product.tagline }}</span>
            <template v-if="product.vendor">
                · by
                <a v-if="product.vendor_url" :href="product.vendor_url" class="underline" target="_blank" rel="noopener">{{ product.vendor }}</a>
                <span v-else>{{ product.vendor }}</span>
            </template>
        </p>
    </footer>
</template>
