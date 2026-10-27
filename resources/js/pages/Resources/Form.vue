<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import AppIcon from '@/components/ui/AppIcon.vue';
import SchemaForm from '@/components/schema/SchemaForm.vue';
import RoleBuilder from '@/components/RoleBuilder.vue';
import { ApiError, api, get } from '@/lib/api';
import { attemptKey, completeAttempt } from '@/lib/idempotency';
import { loadSchema, type SchemaProperty } from '@/lib/meta';
import { whilePosting } from '@/lib/pwa';
import { notify } from '@/lib/toast';
import { fillTemplate } from '@/lib/workflow';
import type { ResourceMeta } from '@/types/shared';

/**
 * Create / edit any resource from its schema; the server validates everything again. Records written
 * outside the generic writer use their dedicated endpoints (`api_create` / `api_update`); postings send
 * a stable Idempotency-Key. `?prefill[field]=…` (follow-up links of another record) pre-fills fields.
 */
const props = defineProps<{ resource: ResourceMeta; id: string | null }>();

const form = ref<Record<string, unknown>>({});
const properties = ref<Record<string, SchemaProperty>>({});
const state = ref<string | null>(null);
const version = ref<number | null>(null);
const busy = ref(false);
const error = ref<unknown>(null);
const ready = ref(props.id === null);
const fieldErrors = computed(() => (error.value instanceof ApiError ? error.value.fieldErrors() : {}));
const schemaName = computed(() => (props.id === null ? props.resource.create_schema ?? props.resource.schema : props.resource.update_schema ?? props.resource.schema));
/** Roles are permission lists picked by administrators: edited with the role builder. */
const isRole = computed(() => props.resource.key === 'roles');
const rolePermissions = computed({
    get: () => (Array.isArray(form.value.permissions) ? form.value.permissions as string[] : []),
    set: (codes: string[]) => (form.value = { ...form.value, permissions: codes }),
});
const scope = computed(() => `POST:${props.resource.api_create ?? props.resource.api}`);
/** What happens after saving a new document: its first step on the happy path. */
const afterSave = computed(() => {
    const wf = props.resource.workflow;
    if (props.id !== null || !wf) {
        return null;
    }
    const first = Object.entries(wf.actions).find(([, a]) => a.primary && a.from.includes(wf.steps[0]));
    const label = first ? props.resource.actions.find((a) => a.name === first[0])?.label ?? null : null;

    return label ? `It is saved as a draft you can still change. Next, on its page: “${label}”.` : null;
});

function editable(schema: Record<string, SchemaProperty>, record: Record<string, any>): Record<string, unknown> {
    const out: Record<string, unknown> = {};
    for (const [name, p] of Object.entries(schema)) {
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

/** `prefill[field]=value` / `prefill[field][]=value` for writable fields of the create schema. */
function prefill(schema: Record<string, SchemaProperty>): Record<string, unknown> {
    const out: Record<string, unknown> = {};
    for (const [key, value] of new URLSearchParams(window.location.search).entries()) {
        const m = /^prefill\[([a-z_]+)\](\[\])?$/.exec(key);
        const p = m ? schema[m[1]] : undefined;
        if (!m || !p || p.server_controlled || value === '') {
            continue;
        }
        out[m[1]] = m[2] ? [...((out[m[1]] as string[] | undefined) ?? []), value] : value;
    }

    return out;
}

onMounted(async () => {
    if (!schemaName.value) {
        return;
    }
    try {
        if (props.id === null) {
            properties.value = (await loadSchema(schemaName.value)).properties;
            form.value = prefill(properties.value);

            return;
        }
        const [schema, record] = await Promise.all([loadSchema(schemaName.value), get(`${props.resource.api}/${props.id}`)]);
        properties.value = schema.properties;
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

async function create(body: Record<string, unknown>) {
    const idempotent = props.resource.create_idempotent;
    const r = await whilePosting(() => api(props.resource.api_create ?? props.resource.api, {
        method: 'POST', body, idempotencyKey: idempotent ? attemptKey(scope.value) : undefined,
    }));
    if (idempotent) {
        completeAttempt(scope.value);
    }
    const created = (Array.isArray(r.data) ? r.data[0] : r.data) as Record<string, unknown>;

    return (props.resource.create_land ? fillTemplate(props.resource.create_land, created) : null) ?? `/r/${props.resource.key}/${created.id}`;
}

async function save() {
    busy.value = true;
    error.value = null;
    try {
        // Only schemas that declare it carry the optimistic-lock version (dedicated endpoints may not).
        const body = { ...form.value, ...(version.value !== null && properties.value.version ? { version: version.value } : {}) };
        let target: string;
        if (props.id === null) {
            target = await create(body);
        } else {
            await api(props.resource.api_update?.replace('{id}', props.id) ?? `${props.resource.api}/${props.id}`, { method: 'PATCH', body });
            target = `/r/${props.resource.key}/${props.id}`;
        }
        notify('Saved.');
        router.visit(target);
    } catch (e) {
        error.value = e;
        if (e instanceof ApiError && e.status === 409) {
            completeAttempt(scope.value);
        }
        window.scrollTo({ top: 0, behavior: 'smooth' });
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <PageHeader :title="id ? `Edit ${resource.noun}` : `New ${resource.noun}`" :back="id ? `/r/${resource.key}/${id}` : `/r/${resource.key}`"
                :back-label="id ? resource.noun : resource.label" />
    <ErrorSummary :error="error" />
    <form v-if="schemaName && ready" class="card space-y-6" novalidate @submit.prevent="save">
        <SchemaForm v-model="form" :schema-name="schemaName" :operation="id ? 'update' : 'create'" :errors="fieldErrors" :state="state"
                    :exclude="isRole ? ['version', 'permissions'] : ['version']" />
        <section v-if="isRole">
            <h2 class="mb-2 font-semibold">Permissions</h2>
            <p v-if="fieldErrors.permissions" class="error-text">{{ fieldErrors.permissions }}</p>
            <RoleBuilder v-model="rolePermissions" />
        </section>
        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-line pt-4">
            <p v-if="afterSave" class="mr-auto flex items-center gap-2 text-sm text-ink-muted"><AppIcon name="info" :size="16" /> {{ afterSave }}</p>
            <p v-else-if="resource.create_idempotent && !id" class="mr-auto flex items-center gap-2 text-sm text-ink-muted">
                <AppIcon name="info" :size="16" /> This posts to the stock ledger straight away. It can only be reversed afterwards.
            </p>
            <UiButton :href="id ? `/r/${resource.key}/${id}` : `/r/${resource.key}`">Cancel</UiButton>
            <UiButton variant="primary" type="submit" :loading="busy">{{ busy && resource.create_idempotent && !id ? 'Posting…' : 'Save' }}</UiButton>
        </div>
    </form>
</template>
