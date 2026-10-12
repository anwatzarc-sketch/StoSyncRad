<script setup lang="ts">
import ProductFooter from '@/components/ProductFooter.vue';
import ToastHost from '@/components/ui/ToastHost.vue';
import { useShared } from '@/composables/useShared';

/** Sign-in pages: the product logo, the customer's name, background and support contacts. */
const { brand } = useShared();
</script>

<template>
    <div
        class="flex min-h-dvh items-center justify-center bg-surface-muted bg-cover bg-center px-4 py-10"
        :style="brand.assets.login_background ? { backgroundImage: `url('${brand.assets.login_background}')` } : undefined"
    >
        <main class="w-full max-w-md">
            <div class="mb-6 text-center">
                <img :src="brand.product.assets.logo" :alt="`${brand.product.name} — ${brand.product.tagline ?? ''}`" class="mx-auto mb-3 h-auto w-72 max-w-full object-contain">
                <p v-if="brand.company_name" class="text-sm font-semibold text-ink">{{ brand.company_name }}</p>
                <p v-if="brand.tagline" class="mt-1 text-sm text-ink-muted">{{ brand.tagline }}</p>
            </div>
            <div class="card p-6">
                <slot />
            </div>
            <p v-if="brand.support_email || brand.support_phone" class="mt-6 text-center text-xs text-ink-muted">
                Need help?
                <a v-if="brand.support_email" :href="`mailto:${brand.support_email}`" class="underline">{{ brand.support_email }}</a>
                <span v-if="brand.support_email && brand.support_phone"> · </span>
                <a v-if="brand.support_phone" :href="`tel:${brand.support_phone}`" class="underline">{{ brand.support_phone }}</a>
            </p>
            <ProductFooter centered class="mt-4" />
        </main>
        <ToastHost />
    </div>
</template>
