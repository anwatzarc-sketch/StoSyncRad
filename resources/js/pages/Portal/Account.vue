<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import PasswordInput from '@/components/ui/PasswordInput.vue';
import { post } from '@/lib/api';
import { formatDateTime } from '@/lib/format';
import { canDo, loadProfile, profile } from '@/lib/portal';
import { notify } from '@/lib/toast';
import type { NextAction } from '@/types/shared';

/**
 * Customer account & security: password change (current password required) and replacing the
 * authenticator (the current code is required; the old one keeps working until the new one is confirmed).
 */
const pw = reactive({ current_password: '', password: '', repeat: '' });
const pwBusy = ref(false);
const pwError = ref<unknown>(null);
const mismatch = computed(() => pw.repeat !== '' && pw.repeat !== pw.password);

const step = ref<'idle' | 'verify' | 'scan' | 'done'>('idle');
const currentCode = ref('');
const newCode = ref('');
const candidate = ref<{ secret: string; qr_svg: string } | null>(null);
const codes = ref<string[]>([]);
const mfaBusy = ref(false);
const mfaError = ref<unknown>(null);
const qr = computed(() => (candidate.value ? `data:image/svg+xml;base64,${btoa(candidate.value.qr_svg)}` : ''));
/** Next action: back to the portal's work — the team for administrators, otherwise home. */
const nextActions = computed<NextAction[]>(() => (canDo('customer.member.read')
    ? [{ label: 'Manage your team', icon: 'users', href: '/portal/team', hint: 'Invite colleagues or change who administers the portal.' }]
    : [{ label: 'Back to home', icon: 'home', href: '/portal/dashboard' }]));

onMounted(() => void loadProfile(true).catch(() => undefined));

async function changePassword() {
    pwBusy.value = true;
    pwError.value = null;
    try {
        await post('/api/v1/portal/account/password', { current_password: pw.current_password, password: pw.password });
        Object.assign(pw, { current_password: '', password: '', repeat: '' });
        notify('Password changed. Other devices were signed out.');
    } catch (e) {
        pwError.value = e;
    } finally {
        pwBusy.value = false;
    }
}

async function beginReplace() {
    mfaBusy.value = true;
    mfaError.value = null;
    try {
        candidate.value = (await post('/api/v1/portal/account/authenticator/begin', { code: currentCode.value.trim() })).data;
        step.value = 'scan';
    } catch (e) {
        mfaError.value = e;
    } finally {
        mfaBusy.value = false;
    }
}

async function confirmReplace() {
    mfaBusy.value = true;
    mfaError.value = null;
    try {
        codes.value = (await post('/api/v1/portal/account/authenticator/confirm', { code: newCode.value.trim() })).data.recovery_codes;
        step.value = 'done';
        currentCode.value = newCode.value = '';
    } catch (e) {
        mfaError.value = e;
    } finally {
        mfaBusy.value = false;
    }
}
</script>

<template>
    <Head title="Account & security" />
    <PageHeader title="Account & security" :next="nextActions" />
    <div class="grid gap-6 lg:grid-cols-2">
        <section class="card space-y-2">
            <h2 class="text-lg font-semibold">Your account</h2>
            <template v-if="profile">
                <p><strong>{{ profile.account.name }}</strong> · {{ profile.account.email }}</p>
                <p class="text-sm text-ink-muted">Member of {{ profile.customer.name }} ({{ profile.customer.code }})</p>
                <p v-if="profile.account.last_login_at" class="text-sm text-ink-muted">Last sign-in {{ formatDateTime(profile.account.last_login_at) }}</p>
                <p class="text-sm text-ink-muted">To change your name, e-mail or company details, please contact us.</p>
            </template>
        </section>

        <form class="card space-y-4" @submit.prevent="changePassword">
            <h2 class="text-lg font-semibold">Change password</h2>
            <ErrorSummary :error="pwError" />
            <div>
                <label for="a-current" class="label">Current password</label>
                <PasswordInput id="a-current" v-model="pw.current_password" autocomplete="current-password" required />
            </div>
            <div>
                <label for="a-new" class="label">New password</label>
                <PasswordInput id="a-new" v-model="pw.password" generate autocomplete="new-password" required @generated="pw.repeat = $event" />
            </div>
            <div>
                <label for="a-repeat" class="label">Repeat new password</label>
                <PasswordInput id="a-repeat" v-model="pw.repeat" autocomplete="new-password" required :class="{ 'input-invalid': mismatch }" />
                <p v-if="mismatch" class="error-text">The passwords do not match.</p>
            </div>
            <UiButton variant="primary" type="submit" :loading="pwBusy" :disabled="mismatch || !pw.password">Change password</UiButton>
        </form>

        <section class="card space-y-4 lg:col-span-2">
            <h2 class="text-lg font-semibold">Two-step verification</h2>
            <p class="text-sm text-ink-muted">Your account always uses an authenticator app. Replace it here if you change phones; you also get new recovery codes.</p>
            <ErrorSummary :error="mfaError" />
            <UiButton v-if="step === 'idle'" @click="step = 'verify'">Replace my authenticator</UiButton>
            <form v-else-if="step === 'verify'" class="flex flex-wrap items-end gap-3" @submit.prevent="beginReplace">
                <div>
                    <label for="m-current" class="label">Code from your current app (or a recovery code)</label>
                    <input id="m-current" v-model="currentCode" class="input font-mono" autocomplete="one-time-code" required>
                </div>
                <UiButton variant="primary" type="submit" :loading="mfaBusy">Continue</UiButton>
            </form>
            <form v-else-if="step === 'scan' && candidate" class="space-y-3" @submit.prevent="confirmReplace">
                <p class="text-sm">Scan this code with the new app, then enter the code it shows.</p>
                <img :src="qr" alt="QR code for the new authenticator" class="size-44 rounded bg-white p-2">
                <p class="break-all font-mono text-xs text-ink-muted">{{ candidate.secret }}</p>
                <div class="flex flex-wrap items-end gap-3">
                    <div>
                        <label for="m-new" class="label">Code from the new app</label>
                        <input id="m-new" v-model="newCode" class="input font-mono" inputmode="numeric" autocomplete="one-time-code" required>
                    </div>
                    <UiButton variant="primary" type="submit" :loading="mfaBusy">Switch</UiButton>
                </div>
            </form>
            <div v-else-if="step === 'done'" class="space-y-2" role="status">
                <p class="font-semibold">Done. Your new recovery codes (shown once):</p>
                <ul class="grid grid-cols-2 gap-2 font-mono text-sm sm:grid-cols-5">
                    <li v-for="c in codes" :key="c" class="rounded border border-line bg-surface-muted px-2 py-1 text-center">{{ c }}</li>
                </ul>
            </div>
        </section>
    </div>
</template>
