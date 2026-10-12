<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppIcon from './AppIcon.vue';

withDefaults(defineProps<{
    variant?: 'primary' | 'accent' | 'secondary' | 'danger' | 'ghost';
    type?: 'button' | 'submit';
    loading?: boolean;
    disabled?: boolean;
    icon?: string;
    href?: string;
    form?: string;
}>(), { variant: 'secondary', type: 'button', loading: false, disabled: false, icon: undefined, href: undefined, form: undefined });
</script>

<template>
    <Link v-if="href" :href="href" :class="['btn', `btn-${variant}`]">
        <AppIcon v-if="icon" :name="icon" :size="18" />
        <slot />
    </Link>
    <button v-else :type="type" :form="form" :class="['btn', `btn-${variant}`]" :disabled="disabled || loading" :aria-busy="loading">
        <span v-if="loading" class="size-4 animate-spin rounded-full border-2 border-current border-t-transparent" aria-hidden="true" />
        <AppIcon v-else-if="icon" :name="icon" :size="18" />
        <slot />
    </button>
</template>
