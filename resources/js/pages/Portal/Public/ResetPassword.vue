<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import PasswordInput from '@/components/ui/PasswordInput.vue';
import { post } from '@/lib/api';

/** Password reset from the e-mailed link (customer accounts). */
const params = new URLSearchParams(window.location.search);
const token = params.get('token') ?? '';
const email = ref(params.get('email') ?? '');
const password = ref('');
const repeat = ref('');
const busy = ref(false);
const done = ref(false);
const error = ref<unknown>(null);
const mismatch = computed(() => repeat.value !== '' && repeat.value !== password.value);

async function save() {
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
    <Head title="Reset password" />
    <div class="mx-auto max-w-md px-4 py-12">
        <div class="card space-y-4 p-6">
            <h1 class="text-xl font-bold">Choose a new password</h1>
            <template v-if="done">
                <p role="status">Your password was changed.</p>
                <Link href="/portal/login" class="btn btn-primary">Sign in</Link>
            </template>
            <form v-else class="space-y-4" @submit.prevent="save">
                <ErrorSummary :error="error" />
                <div>
                    <label for="rp-email" class="label">E-mail</label>
                    <input id="rp-email" v-model="email" type="email" class="input" autocomplete="username" required>
                </div>
                <div>
                    <label for="rp-pw" class="label">New password</label>
                    <PasswordInput id="rp-pw" v-model="password" generate autocomplete="new-password" required @generated="repeat = $event" />
                </div>
                <div>
                    <label for="rp-pw2" class="label">Repeat new password</label>
                    <PasswordInput id="rp-pw2" v-model="repeat" autocomplete="new-password" required :class="{ 'input-invalid': mismatch }" />
                    <p v-if="mismatch" class="error-text">The passwords do not match.</p>
                </div>
                <UiButton variant="primary" type="submit" class="w-full" :loading="busy" :disabled="!token || mismatch || !password">Save password</UiButton>
                <p v-if="!token" class="error-text">This link is incomplete. Request a new one from the sign-in page.</p>
            </form>
        </div>
    </div>
</template>
