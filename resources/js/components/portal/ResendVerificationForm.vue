<script setup lang="ts">
import { ref } from 'vue';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import { post } from '@/lib/api';

/**
 * "Send me a new link": for a registration whose confirmation e-mail never arrived or whose link expired.
 * The server answers the same way for every address, so this form never reveals who is registered.
 */
const props = withDefaults(defineProps<{ email?: string; compact?: boolean }>(), { email: '', compact: false });

const address = ref(props.email);
const busy = ref(false);
const error = ref<unknown>(null);
const sent = ref<string | null>(null);

async function resend() {
    busy.value = true;
    error.value = null;
    try {
        sent.value = (await post('/api/v1/portal/public/register/resend', { email: address.value.trim() })).data.message;
    } catch (e) {
        error.value = e;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="space-y-3">
        <p v-if="sent" class="rounded-brand bg-accent/10 p-3 text-sm" role="status">{{ sent }} Check your spam folder too.</p>
        <form v-else class="space-y-3" @submit.prevent="resend">
            <ErrorSummary :error="error" />
            <div v-if="!compact || !email">
                <label for="resend-email" class="label">E-mail address you registered with</label>
                <input id="resend-email" v-model="address" type="email" class="input" autocomplete="email" required>
            </div>
            <UiButton type="submit" :variant="compact ? 'secondary' : 'accent'" :loading="busy" :disabled="!address.trim()">Send a new confirmation link</UiButton>
        </form>
    </div>
</template>
