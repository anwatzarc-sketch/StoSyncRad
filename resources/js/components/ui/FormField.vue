<script setup lang="ts">
defineProps<{ id: string; label: string; error?: string | null; help?: string | null; required?: boolean; locked?: string | null }>();
</script>

<template>
    <div>
        <label :for="id" class="label">
            {{ label }}
            <span v-if="required" class="text-danger" aria-hidden="true">*</span>
            <span v-if="required" class="sr-only">(required)</span>
        </label>
        <slot />
        <p v-if="locked" class="help">🔒 {{ locked }}</p>
        <p v-if="error" :id="`${id}-error`" class="error-text" role="alert">{{ error }}</p>
        <p v-else-if="help" :id="`${id}-help`" class="help">{{ help }}</p>
    </div>
</template>
