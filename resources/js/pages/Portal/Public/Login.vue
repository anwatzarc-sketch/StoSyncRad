<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import PasswordInput from '@/components/ui/PasswordInput.vue';
import { post } from '@/lib/api';

/** Customer sign-in: e-mail + password, then the authenticator code. Staff use the main sign-in page. */
const step = ref<'password' | 'mfa' | 'forgot' | 'sent'>('password');
const identifier = ref('');
const password = ref('');
const code = ref('');
const remember = ref(false);
const busy = ref(false);
const error = ref<unknown>(null);

function done() {
    const next = new URLSearchParams(window.location.search).get('next');
    window.location.assign(next && next.startsWith('/portal/') ? next : '/portal/dashboard');
}

async function signIn() {
    busy.value = true;
    error.value = null;
    try {
        const { data } = await post('/api/v1/portal/public/login', { identifier: identifier.value, password: password.value });
        data.status === 'mfa_required' ? (step.value = 'mfa') : done();
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
        await post('/api/v1/portal/public/mfa', { code: code.value.trim(), remember_device: remember.value });
        done();
    } catch (e) {
        error.value = e;
    } finally {
        busy.value = false;
    }
}

async function forgot() {
    busy.value = true;
    try {
        await post('/api/v1/auth/password/forgot', { identifier: identifier.value });
    } finally {
        busy.value = false;
        step.value = 'sent';
    }
}
</script>

<template>
    <Head title="Sign in" />
    <div class="mx-auto max-w-md px-4 py-12">
        <div class="card space-y-4 p-6">
            <h1 class="text-xl font-bold">Customer sign-in</h1>
            <ErrorSummary :error="error" />

            <form v-if="step === 'password'" class="space-y-4" @submit.prevent="signIn">
                <div>
                    <label for="l-id" class="label">E-mail</label>
                    <input id="l-id" v-model="identifier" class="input" type="email" autocomplete="username" required>
                </div>
                <div>
                    <label for="l-pw" class="label">Password</label>
                    <PasswordInput id="l-pw" v-model="password" autocomplete="current-password" required />
                </div>
                <UiButton variant="primary" type="submit" class="w-full" :loading="busy">Continue</UiButton>
                <button type="button" class="block min-h-11 w-full text-center text-sm text-ink-muted underline" @click="step = 'forgot'">Forgot your password?</button>
                <p class="text-center text-sm text-ink-muted">New customer? <Link href="/portal/register" class="underline">Sign up</Link></p>
                <p class="text-center text-sm text-ink-muted">
                    Registered but no confirmation e-mail? <Link :href="`/portal/verify-email/resend${identifier.includes('@') ? `?email=${encodeURIComponent(identifier)}` : ''}`" class="underline">Send a new link</Link>
                </p>
            </form>

            <form v-else-if="step === 'mfa'" class="space-y-4" @submit.prevent="verify">
                <div>
                    <label for="l-code" class="label">Code from your authenticator app</label>
                    <input id="l-code" v-model="code" class="input text-center font-mono text-lg tracking-widest" inputmode="numeric" autocomplete="one-time-code" required>
                    <p class="help">Lost your phone? Enter one of your recovery codes instead.</p>
                </div>
                <label class="flex min-h-11 items-center gap-2 text-sm"><input v-model="remember" type="checkbox" class="size-5 accent-brand"> Trust this device</label>
                <UiButton variant="primary" type="submit" class="w-full" :loading="busy">Sign in</UiButton>
            </form>

            <form v-else-if="step === 'forgot'" class="space-y-4" @submit.prevent="forgot">
                <div>
                    <label for="f-id" class="label">E-mail</label>
                    <input id="f-id" v-model="identifier" class="input" type="email" autocomplete="username" required>
                </div>
                <UiButton variant="primary" type="submit" class="w-full" :loading="busy">Send a reset link</UiButton>
                <button type="button" class="block min-h-11 w-full text-center text-sm underline" @click="step = 'password'">Back</button>
            </form>

            <p v-else class="text-sm" role="status">If the address belongs to an account, we sent a link to reset the password. It is valid for 60 minutes.</p>
        </div>
    </div>
</template>
