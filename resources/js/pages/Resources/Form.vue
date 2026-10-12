<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import SchemaForm from '@/components/schema/SchemaForm.vue';
import RoleBuilder from '@/components/RoleBuilder.vue';
import { ApiError, api, get } from '@/lib/api';
import { loadSchema, type SchemaProperty } from '@/lib/meta';
import { notify } from '@/lib/toast';
import type { ResourceMeta } from '@/types/shared';

/** Create / edit any resource from its schema; the server validates everything again. */
const props = defineProps<{ resource: ResourceMeta; id: string | null }>();

const form = ref<Record<string, unknown>>({});
const state = ref<string | null>(null);
const version = ref<number | null>(null);
const busy = ref(false);
const error = ref<unknown>(null);
const ready = ref(props.id === null);
const fieldErrors = computed(() => (error.value instanceof ApiError ? error.value.fieldErrors() : {}));
/** Roles are permission lists picked by administrators: edited with the role builder. */
const isRole = computed(() => props.resource.key === 'roles');
const rolePermissions = computed({
    get: () => (Array.isArray(form.value.permissions) ? form.value.permissions as string[] : []),
    set: (codes: string[]) => (form.value = { ...form.value, permissions: codes }),
});

function editable(properties: Record<string, SchemaProperty>, record: Record<string, any>): Record<string, unknown> {
    const out: Record<string, unknown> = {};
    for (const [name, p] of Object.entries(properties)) {
        if (p.server_controlled || name === 'id') {
            continue;
        }
        if (p.type === 'array' && p.items && Array.isArray(record[name])) {
            out[name] = record[name].map((row: Record<string, unknown>) => ({ ...row }));
        } else if (record[name] !== undefined) {
            out[name] = record[name];
        }
    }

    return out;
}

onMounted(async () => {
    if (props.id === null || !props.resource.schema) {
        return;
    }
    try {
        const [schema, record] = await Promise.all([loadSchema(props.resource.schema), get(`${props.resource.api}/${props.id}`)]);
        form.value = editable(schema.properties, record.data);
        if (isRole.value && Array.isArray(record.data.permissions)) {
            form.value.permissions = record.data.permissions.map((p: { permission_code?: string } | string) => (typeof p === 'string' ? p : p.permission_code));
        }
        state.value = record.data.status ?? null;
        version.value = record.data.version ?? null;
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
        const body = { ...form.value, ...(version.value !== null ? { version: version.value } : {}) };
        const r = props.id === null
            ? await api(props.resource.api, { method: 'POST', body })
            : await api(`${props.resource.api}/${props.id}`, { method: 'PATCH', body });
        notify('Saved.');
        router.visit(`/r/${props.resource.key}/${r.data.id}`);
    } catch (e) {
        error.value = e;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <PageHeader :title="id ? `Edit ${resource.label.toLowerCase()}` : `New ${resource.label.toLowerCase()}`" :back="id ? `/r/${resource.key}/${id}` : `/r/${resource.key}`" />
    <ErrorSummary :error="error" />
    <form v-if="resource.schema && ready" class="card space-y-6" novalidate @submit.prevent="save">
        <SchemaForm v-model="form" :schema-name="resource.schema" :operation="id ? 'update' : 'create'" :errors="fieldErrors" :state="state"
                    :exclude="isRole ? ['version', 'permissions'] : ['version']" />
        <section v-if="isRole">
            <h2 class="mb-2 font-semibold">Permissions</h2>
            <p v-if="fieldErrors.permissions" class="error-text">{{ fieldErrors.permissions }}</p>
            <RoleBuilder v-model="rolePermissions" />
        </section>
        <div class="flex flex-wrap justify-end gap-2 border-t border-line pt-4">
            <UiButton :href="id ? `/r/${resource.key}/${id}` : `/r/${resource.key}`">Cancel</UiButton>
            <UiButton variant="primary" type="submit" :loading="busy">Save</UiButton>
        </div>
    </form>
</template>
