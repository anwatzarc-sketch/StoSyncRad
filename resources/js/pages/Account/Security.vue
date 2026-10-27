<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import PasswordInput from '@/components/ui/PasswordInput.vue';
import { get, post } from '@/lib/api';
import { notify } from '@/lib/toast';
import { formatDateTime } from '@/lib/format';
import type { NextAction } from '@/types/shared';

const required = new URLSearchParams(window.location.search).get('required');
const me = ref<Record<string, any> | null>(null);
const current = ref('');
const next = ref('');
const repeat = ref('');
const passwordBusy = ref(false);
const passwordError = ref<unknown>(null);

const enrolment = ref<{ secret: string; otpauth_uri: string; qr_svg: string } | null>(null);
const code = ref('');
const recovery = ref<string[] | null>(null);
const mfaBusy = ref(false);
const mfaError = ref<unknown>(null);
const qr = computed(() => (enrolment.value ? `data:image/svg+xml;base64,${btoa(enrolment.value.qr_svg)}` : ''));
/** Next action: what the sign-in policy still requires, else two-step verification, else back to work. */
const nextActions = computed<NextAction[]>(() => {
    if (required === 'PASSWORD_CHANGE_REQUIRED') {
        return [{ label: 'Change your password', icon: 'lock', onClick: () => document.getElementById('current-password')?.focus(), hint: 'You need a new password before you can continue.' }];
    }
    if (me.value && me.value.account.mfa_status !== 'enrolled') {
        return [{ label: 'Set up two-step verification', icon: 'smartphone', onClick: () => void beginMfa(),
            hint: required === 'MFA_ENROLLMENT_REQUIRED' ? 'Your role requires it before you can continue.' : 'A code from your phone keeps your account safe even if the password leaks.' }];
    }

    return me.value ? [{ label: 'Back to my work', icon: 'home', href: '/', hint: 'Your account is protected with two-step verification.' }] : [];
});

onMounted(async () => {
    me.value = (await get('/api/v1/me')).data;
});

async function changePassword() {
    if (next.value !== repeat.value) {
        passwordError.value = new Error('The new passwords do not match.');

        return;
    }
    passwordBusy.value = true;
    passwordError.value = null;
    try {
        await post('/api/v1/me/password', { current_password: current.value, password: next.value });
        current.value = next.value = repeat.value = '';
        notify('Password changed.');
        if (required === 'PASSWORD_CHANGE_REQUIRED') {
            window.location.assign('/');
        }
    } catch (e) {
        passwordError.value = e;
    } finally {
        passwordBusy.value = false;
    }
}

async function beginMfa() {
    mfaBusy.value = true;
    mfaError.value = null;
    try {
        enrolment.value = (await post('/api/v1/me/mfa/enroll')).data;
    } catch (e) {
        mfaError.value = e;
    } finally {
        mfaBusy.value = false;
    }
}

async function confirmMfa() {
    mfaBusy.value = true;
    mfaError.value = null;
    try {
        recovery.value = (await post('/api/v1/me/mfa/confirm', { code: code.value.trim() })).data.recovery_codes;
        enrolment.value = null;
        me.value = (await get('/api/v1/me')).data;
    } catch (e) {
        mfaError.value = e;
    } finally {
        mfaBusy.value = false;
    }
}
</script>

<template>
    <PageHeader title="Account & security" :subtitle="me ? `${me.account.display_name} · ${me.account.username}` : null" :next="nextActions" />

    <div v-if="required === 'PASSWORD_CHANGE_REQUIRED'" class="mb-4 rounded-brand border border-warning/40 bg-warning/10 p-4 text-sm" role="alert">
        Your password must be changed before you continue.
    </div>
    <div v-if="required === 'MFA_ENROLLMENT_REQUIRED'" class="mb-4 rounded-brand border border-warning/40 bg-warning/10 p-4 text-sm" role="alert">
        Set up two-step verification before you continue.
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="card">
            <h2 class="mb-3 text-lg font-semibold">Password</h2>
            <ErrorSummary :error="passwordError" />
            <form class="space-y-4" @submit.prevent="changePassword">
                <div>
                    <label for="current-password" class="label">Current password</label>
                    <PasswordInput id="current-password" v-model="current" autocomplete="current-password" required />
                </div>
                <div>
                    <label for="next-password" class="label">New password</label>
                    <PasswordInput id="next-password" v-model="next" generate autocomplete="new-password" required @generated="repeat = $event" />
                </div>
                <div>
                    <label for="repeat-password" class="label">Repeat the new password</label>
                    <PasswordInput id="repeat-password" v-model="repeat" autocomplete="new-password" required />
                </div>
                <UiButton variant="primary" type="submit" :loading="passwordBusy">Change password</UiButton>
            </form>
        </section>

        <section class="card">
            <h2 class="mb-1 text-lg font-semibold">Two-step verification</h2>
            <p class="mb-3 text-sm text-ink-muted">
                Status: <strong>{{ me?.account.mfa_status === 'enrolled' ? 'On' : 'Off' }}</strong>
                <span v-if="me?.account.last_login_at"> · Last sign-in {{ formatDateTime(me.account.last_login_at) }}</span>
            </p>
            <ErrorSummary :error="mfaError" />

            <div v-if="recovery" class="space-y-3">
                <p class="text-sm font-semibold">Save these recovery codes now — each works once and they are not shown again.</p>
                <ul class="grid grid-cols-2 gap-2 rounded-brand bg-surface-muted p-3 font-mono text-sm">
                    <li v-for="c in recovery" :key="c">{{ c }}</li>
                </ul>
                <a v-if="required" href="/" class="btn btn-primary">Continue</a>
            </div>
            <div v-else-if="enrolment" class="space-y-3">
                <p class="text-sm">Scan this code with your authenticator app, then enter the 6-digit code it shows.</p>
                <img :src="qr" alt="Authenticator QR code" class="mx-auto size-52 rounded bg-white p-2">
                <p class="break-all text-center font-mono text-xs text-ink-muted">{{ enrolment.secret }}</p>
                <form class="flex gap-2" @submit.prevent="confirmMfa">
                    <label for="mfa-code" class="sr-only">Code</label>
                    <input id="mfa-code" v-model="code" class="input font-mono" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" required>
                    <UiButton variant="primary" type="submit" :loading="mfaBusy">Confirm</UiButton>
                </form>
            </div>
            <UiButton v-else variant="accent" :loading="mfaBusy" @click="beginMfa">
                {{ me?.account.mfa_status === 'enrolled' ? 'Set up a new authenticator' : 'Set up two-step verification' }}
            </UiButton>
        </section>
    </div>
</template>
