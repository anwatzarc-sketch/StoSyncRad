<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import SchemaForm from '@/components/schema/SchemaForm.vue';
import { ApiError, get, put } from '@/lib/api';
import { notify } from '@/lib/toast';
import { useShared } from '@/composables/useShared';

const { can } = useShared();
const form = ref<Record<string, unknown>>({});
const ready = ref(false);
const busy = ref(false);
const error = ref<unknown>(null);
const fieldErrors = computed(() => (error.value instanceof ApiError ? error.value.fieldErrors() : {}));

onMounted(async () => {
    try {
        const row = (await get('/api/v1/admin/company')).data as Record<string, unknown>;
        for (const k of ['registered_address', 'operational_address']) {
            if (typeof row[k] === 'string') {
                row[k] = JSON.parse(row[k] as string);
            }
        }
        form.value = Object.fromEntries(Object.entries(row).filter(([k]) => !['id', 'singleton', 'created_at', 'updated_at', 'created_by', 'updated_by'].includes(k)));
    } catch (e) {
        error.value = e;
    } finally {
        ready.value = true;
    }
});

async function save() {
    busy.value = true;
    error.value = null;
    try {
        await put('/api/v1/admin/company', form.value);
        notify('Company profile saved.');
    } catch (e) {
        error.value = e;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <PageHeader title="Company profile" />
    <ErrorSummary :error="error" />
    <form v-if="ready" class="card space-y-6" @submit.prevent="save">
        <SchemaForm v-model="form" schema-name="company_profile" operation="update" :errors="fieldErrors" />
        <div v-if="can('platform.company.manage')" class="flex justify-end border-t border-line pt-4">
            <UiButton type="submit" variant="primary" :loading="busy">Save</UiButton>
        </div>
    </form>
</template>
