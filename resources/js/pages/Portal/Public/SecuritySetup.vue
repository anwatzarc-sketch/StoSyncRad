<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import { ApiError, post } from '@/lib/api';

/**
 * Two-step verification during registration (CP-1.1 section 4: TFA is verified before activation).
 * Reached only from the e-mail or invitation link in the same browser.
 */
const setup = ref<{ secret: string; qr_svg: string } | null>(null);
const code = ref('');
const busy = ref(false);
const error = ref<unknown>(null);
const result = ref<{ status: string; recovery_codes: string[]; message: string } | null>(null);
const qr = computed(() => (setup.value ? `data:image/svg+xml;base64,${btoa(setup.value.qr_svg)}` : ''));
const fieldError = computed(() => (error.value instanceof ApiError ? error.value.fieldErrors().code : undefined));

onMounted(async () => {
    try {
        setup.value = (await post('/api/v1/portal/public/security/begin')).data;
    } catch (e) {
        error.value = e;
    }
});

async function confirm() {
    busy.value = true;
    error.value = null;
    try {
        result.value = (await post('/api/v1/portal/public/security/confirm', { code: code.value.trim() })).data;
    } catch (e) {
        error.value = e;
    } finally {
        busy.value = false;
    }
}

function downloadCodes() {
    const blob = new Blob([`Recovery codes\n\n${result.value!.recovery_codes.join('\n')}\n\nEach code works once. Keep them somewhere safe.\n`], { type: 'text/plain' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'recovery-codes.txt';
    a.click();
    URL.revokeObjectURL(a.href);
}
</script>

<template>
    <Head title="Set up two-step verification" />
    <div class="mx-auto max-w-lg px-4 py-10">
        <h1 class="text-2xl font-bold">Secure your account</h1>

        <div v-if="result" class="card mt-6 space-y-4" role="status">
            <p class="font-semibold">{{ result.message }}</p>
            <div>
                <h2 class="font-semibold">Your recovery codes</h2>
                <p class="text-sm text-ink-muted">Use one if you lose your phone. Each works once and they are shown only now.</p>
                <ul class="mt-3 grid grid-cols-2 gap-2 font-mono text-sm">
                    <li v-for="c in result.recovery_codes" :key="c" class="rounded border border-line bg-surface-muted px-2 py-1 text-center">{{ c }}</li>
                </ul>
                <UiButton class="mt-3" icon="download" @click="downloadCodes">Download codes</UiButton>
            </div>
            <Link v-if="result.status === 'approved'" href="/portal/login" class="btn btn-primary">Sign in</Link>
        </div>

        <form v-else class="card mt-6 space-y-4" @submit.prevent="confirm">
            <ErrorSummary v-if="!fieldError" :error="error" />
            <template v-if="setup">
                <ol class="list-decimal space-y-2 pl-5 text-sm">
                    <li>Install an authenticator app on your phone (for example Google or Microsoft Authenticator).</li>
                    <li>Scan this code with the app, or type the key below.</li>
                    <li>Enter the 6-digit code the app shows.</li>
                </ol>
                <img :src="qr" alt="QR code for your authenticator app" class="mx-auto size-48 rounded bg-white p-2">
                <p class="break-all text-center font-mono text-xs text-ink-muted">{{ setup.secret }}</p>
                <div>
                    <label for="mfa-code" class="label">6-digit code</label>
                    <input id="mfa-code" v-model="code" class="input text-center font-mono text-lg tracking-widest" inputmode="numeric" autocomplete="one-time-code"
                           maxlength="8" required :class="{ 'input-invalid': fieldError }">
                    <p v-if="fieldError" class="error-text">{{ fieldError }}</p>
                </div>
                <UiButton variant="primary" type="submit" :loading="busy" :disabled="code.trim().length < 6">Confirm</UiButton>
            </template>
            <p v-else-if="!error" class="text-sm text-ink-muted">Preparing…</p>
        </form>
    </div>
</template>
