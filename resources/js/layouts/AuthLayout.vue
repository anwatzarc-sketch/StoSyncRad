<script setup lang="ts">
import ToastHost from '@/components/ui/ToastHost.vue';
import { useShared } from '@/composables/useShared';

/** Sign-in pages: brand logo, background and support contacts from the active brand kit. */
const { brand } = useShared();
</script>

<template>
    <div
        class="flex min-h-dvh items-center justify-center bg-surface-muted bg-cover bg-center px-4 py-10"
        :style="brand.assets.login_background ? { backgroundImage: `url('${brand.assets.login_background}')` } : undefined"
    >
        <main class="w-full max-w-md">
            <div class="mb-6 text-center">
                <img v-if="brand.assets.logo_light" :src="brand.assets.logo_light" :alt="brand.app_name" class="mx-auto mb-3 max-h-16 object-contain">
                <h1 v-else class="text-2xl font-bold text-ink">{{ brand.app_name }}</h1>
                <p v-if="brand.tagline" class="mt-1 text-sm text-ink-muted">{{ brand.tagline }}</p>
            </div>
            <div class="card p-6">
                <slot />
            </div>
            <footer class="mt-6 space-y-1 text-center text-xs text-ink-muted">
                <p v-if="brand.support_email || brand.support_phone">
                    Need help?
                    <a v-if="brand.support_email" :href="`mailto:${brand.support_email}`" class="underline">{{ brand.support_email }}</a>
                    <span v-if="brand.support_email && brand.support_phone"> · </span>
                    <a v-if="brand.support_phone" :href="`tel:${brand.support_phone}`" class="underline">{{ brand.support_phone }}</a>
                </p>
                <p v-if="brand.legal_footer">{{ brand.legal_footer }}</p>
            </footer>
        </main>
        <ToastHost />
    </div>
</template>
