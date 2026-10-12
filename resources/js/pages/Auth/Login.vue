<script setup lang="ts">
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import PasswordInput from '@/components/ui/PasswordInput.vue';
import { post } from '@/lib/api';
import { useShared } from '@/composables/useShared';

const { brand } = useShared();
const step = ref<'password' | 'mfa' | 'forgot' | 'sent'>('password');
const identifier = ref('');
const password = ref('');
const code = ref('');
const rememberDevice = ref(false);
const busy = ref(false);
const error = ref<unknown>(null);

function finish(data: { status: string; must_change_password?: boolean; mfa_enrollment_required?: boolean }) {
    if (data.must_change_password) {
        window.location.assign('/account?required=PASSWORD_CHANGE_REQUIRED');
    } else if (data.mfa_enrollment_required) {
        window.location.assign('/account?required=MFA_ENROLLMENT_REQUIRED');
    } else {
        const intended = new URLSearchParams(window.location.search).get('next');
        window.location.assign(intended && intended.startsWith('/') && !intended.startsWith('//') ? intended : '/');
    }
}

async function signIn() {
    busy.value = true;
    error.value = null;
    try {
        const { data } = await post('/api/v1/auth/login', { identifier: identifier.value, password: password.value });
        password.value = '';
        data.status === 'mfa_required' ? (step.value = 'mfa') : finish(data);
    } catch (e) {
        error.value = e;
    } finally {
        busy.value = false;
    }
}

async function verify() {
    busy.value = true;
    error.value = null;
    try {
        finish((await post('/api/v1/auth/mfa', { code: code.value.trim(), remember_device: rememberDevice.value })).data);
    } catch (e) {
        error.value = e;
    } finally {
        busy.value = false;
    }
}

async function forgot() {
    busy.value = true;
    error.value = null;
    try {
        await post('/api/v1/auth/password/forgot', { identifier: identifier.value });
        step.value = 'sent';
    } catch (e) {
        error.value = e;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Head title="Sign in" />
    <ErrorSummary :error="error" />

    <form v-if="step === 'password'" class="space-y-4" @submit.prevent="signIn">
        <h2 class="text-xl font-semibold">Sign in to {{ brand.app_short_name }}</h2>
        <div>
            <label for="identifier" class="label">Username or email</label>
            <input id="identifier" v-model="identifier" class="input" autocomplete="username" autocapitalize="off" required autofocus>
        </div>
        <div>
            <label for="password" class="label">Password</label>
            <PasswordInput id="password" v-model="password" autocomplete="current-password" required />
        </div>
        <UiButton variant="primary" type="submit" class="w-full" :loading="busy">Sign in</UiButton>
        <button type="button" class="block min-h-11 w-full text-center text-sm text-ink-muted underline" @click="step = 'forgot'">Forgot your password?</button>
    </form>

    <form v-else-if="step === 'mfa'" class="space-y-4" @submit.prevent="verify">
        <h2 class="text-xl font-semibold">Two-step verification</h2>
        <p class="text-sm text-ink-muted">Enter the 6-digit code from your authenticator app, or one of your recovery codes.</p>
        <div>
            <label for="code" class="label">Code</label>
            <input id="code" v-model="code" class="input text-center font-mono text-lg tracking-widest" inputmode="numeric" autocomplete="one-time-code" required autofocus>
        </div>
        <label class="flex min-h-11 items-center gap-3 text-sm">
            <input v-model="rememberDevice" type="checkbox" class="size-5 accent-brand"> Trust this device
        </label>
        <UiButton variant="primary" type="submit" class="w-full" :loading="busy">Verify</UiButton>
    </form>

    <form v-else-if="step === 'forgot'" class="space-y-4" @submit.prevent="forgot">
        <h2 class="text-xl font-semibold">Reset your password</h2>
        <div>
            <label for="forgot-identifier" class="label">Username or email</label>
            <input id="forgot-identifier" v-model="identifier" class="input" autocomplete="username" required>
        </div>
        <UiButton variant="primary" type="submit" class="w-full" :loading="busy">Send reset link</UiButton>
        <button type="button" class="block min-h-11 w-full text-center text-sm text-ink-muted underline" @click="step = 'password'">Back to sign in</button>
    </form>

    <div v-else class="space-y-4 text-center">
        <h2 class="text-xl font-semibold">Check your email</h2>
        <p class="text-sm text-ink-muted">If an account matches, we sent a link to reset the password. It expires soon and works once.</p>
        <UiButton @click="step = 'password'">Back to sign in</UiButton>
    </div>
</template>
