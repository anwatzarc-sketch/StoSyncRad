<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import PasswordInput from '@/components/ui/PasswordInput.vue';
import { ApiError, get, post } from '@/lib/api';

/** Accepting a staff invitation: the contact sets their password, then two-step verification. */
const params = new URLSearchParams(window.location.search);
const invitation = params.get('invitation') ?? '';
const token = params.get('token') ?? '';
const preview = ref<{ email: string; full_name: string; customer_name: string } | null>(null);
const form = reactive({ full_name: '', phone: '', password: '', password_confirmation: '' });
const busy = ref(false);
const error = ref<unknown>(null);
const fieldErrors = computed(() => (error.value instanceof ApiError ? error.value.fieldErrors() : {}));
const mismatch = computed(() => form.password_confirmation !== '' && form.password !== form.password_confirmation);

onMounted(async () => {
    try {
        preview.value = (await get('/api/v1/portal/public/invitation', { invitation, token })).data;
        form.full_name = preview.value!.full_name;
    } catch (e) {
        error.value = e;
    }
});

async function accept() {
    busy.value = true;
    error.value = null;
    try {
        await post('/api/v1/portal/public/invitation/accept', { invitation, token, full_name: form.full_name, phone: form.phone || null,
            password: form.password, password_confirmation: form.password_confirmation });
        router.visit('/portal/security-setup');
    } catch (e) {
        error.value = e;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Head title="Accept invitation" />
    <div class="mx-auto max-w-lg px-4 py-10">
        <h1 class="text-2xl font-bold">Join the customer portal</h1>
        <ErrorSummary :error="error" class="mt-4" />
        <form v-if="preview" class="card mt-6 space-y-4" @submit.prevent="accept">
            <p>You were invited to use the portal for <strong>{{ preview.customer_name }}</strong> as <strong>{{ preview.email }}</strong>.</p>
            <div>
                <label for="i-name" class="label">Full name</label>
                <input id="i-name" v-model="form.full_name" class="input" autocomplete="name" required>
            </div>
            <div>
                <label for="i-phone" class="label">Phone</label>
                <input id="i-phone" v-model="form.phone" type="tel" class="input" autocomplete="tel">
            </div>
            <div>
                <label for="i-password" class="label">Choose a password</label>
                <PasswordInput id="i-password" v-model="form.password" generate autocomplete="new-password" required :class="{ 'input-invalid': fieldErrors.password }"
                               @generated="form.password_confirmation = $event" />
                <p v-if="fieldErrors.password" class="error-text">{{ fieldErrors.password }}</p>
            </div>
            <div>
                <label for="i-password2" class="label">Repeat password</label>
                <PasswordInput id="i-password2" v-model="form.password_confirmation" autocomplete="new-password" required :class="{ 'input-invalid': mismatch }" />
                <p v-if="mismatch" class="error-text">The passwords do not match.</p>
            </div>
            <UiButton variant="primary" type="submit" :loading="busy" :disabled="mismatch || !form.password">Continue</UiButton>
        </form>
    </div>
</template>
