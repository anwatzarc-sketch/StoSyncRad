<script setup lang="ts">
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import { post } from '@/lib/api';

const params = new URLSearchParams(window.location.search);
const email = ref(params.get('email') ?? '');
const token = params.get('token') ?? '';
const password = ref('');
const confirm = ref('');
const busy = ref(false);
const done = ref(false);
const error = ref<unknown>(null);

async function submit() {
    if (password.value !== confirm.value) {
        error.value = new Error('The passwords do not match.');

        return;
    }
    busy.value = true;
    error.value = null;
    try {
        await post('/api/v1/auth/password/reset', { email: email.value, token, password: password.value });
        done.value = true;
    } catch (e) {
        error.value = e;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Head title="Choose a new password" />
    <div v-if="done" class="space-y-4 text-center">
        <h2 class="text-xl font-semibold">Password changed</h2>
        <a href="/login" class="btn btn-primary w-full">Sign in</a>
    </div>
    <form v-else class="space-y-4" @submit.prevent="submit">
        <h2 class="text-xl font-semibold">Choose a new password</h2>
        <ErrorSummary :error="error" />
        <div>
            <label for="email" class="label">Email</label>
            <input id="email" v-model="email" class="input" type="email" autocomplete="username" required>
        </div>
        <div>
            <label for="new-password" class="label">New password</label>
            <input id="new-password" v-model="password" class="input" type="password" autocomplete="new-password" required>
        </div>
        <div>
            <label for="confirm-password" class="label">Repeat the new password</label>
            <input id="confirm-password" v-model="confirm" class="input" type="password" autocomplete="new-password" required>
        </div>
        <UiButton variant="primary" type="submit" class="w-full" :loading="busy" :disabled="!token">Save password</UiButton>
        <p v-if="!token" class="error-text">This link is incomplete. Request a new one from the sign-in page.</p>
    </form>
</template>
