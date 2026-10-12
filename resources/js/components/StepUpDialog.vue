<script setup lang="ts">
import { ref, watch } from 'vue';
import ModalDialog from './ui/ModalDialog.vue';
import UiButton from './ui/UiButton.vue';
import PasswordInput from './ui/PasswordInput.vue';
import { ApiError, post } from '@/lib/api';
import { finishStepUp, stepUpState } from '@/lib/stepUp';

/** Re-verification prompt shared by every screen (Chapter 04 §4.2). */
const password = ref('');
const code = ref('');
const error = ref<string | null>(null);
const busy = ref(false);

watch(() => stepUpState.open, (open) => {
    if (open) {
        password.value = '';
        code.value = '';
        error.value = null;
    }
});

async function verify() {
    busy.value = true;
    error.value = null;
    try {
        await post('/api/v1/auth/step-up', { password: password.value || undefined, code: code.value || undefined }, { noStepUp: true });
        finishStepUp(true);
    } catch (e) {
        error.value = e instanceof ApiError ? e.message : 'Verification failed.';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <ModalDialog :open="stepUpState.open" title="Confirm it's you" @close="finishStepUp(false)">
        <form id="step-up-form" class="space-y-4" @submit.prevent="verify">
            <p class="text-sm text-ink-muted">This action needs a recent verification. Enter your authenticator code, or your password if you do not use one.</p>
            <div>
                <label for="step-up-code" class="label">Authenticator code</label>
                <input id="step-up-code" v-model="code" class="input" inputmode="numeric" autocomplete="one-time-code" maxlength="32">
            </div>
            <div>
                <label for="step-up-password" class="label">Password</label>
                <PasswordInput id="step-up-password" v-model="password" autocomplete="current-password" />
            </div>
            <p v-if="error" class="error-text" role="alert">{{ error }}</p>
        </form>
        <template #footer>
            <UiButton @click="finishStepUp(false)">Cancel</UiButton>
            <UiButton variant="primary" type="submit" form="step-up-form" :loading="busy" :disabled="!password && !code">Verify</UiButton>
        </template>
    </ModalDialog>
</template>
