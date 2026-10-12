<script setup lang="ts">
import { onMounted, ref } from 'vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import { get, patch } from '@/lib/api';
import { notify } from '@/lib/toast';
import { useShared } from '@/composables/useShared';

const { can } = useShared();
const carriers = ref<Record<string, any>[]>([]);
const templates = ref<Record<string, string>>({});
const error = ref<unknown>(null);

onMounted(async () => {
    try {
        carriers.value = (await get('/api/v1/carriers')).data;
        templates.value = Object.fromEntries(carriers.value.map((c) => [c.id, c.tracking_url_template ?? '']));
    } catch (e) {
        error.value = e;
    }
});

async function save(id: string) {
    error.value = null;
    try {
        await patch(`/api/v1/carriers/${id}`, { tracking_url_template: templates.value[id] || null });
        notify('Carrier saved.');
    } catch (e) {
        error.value = e;
    }
}
</script>

<template>
    <PageHeader title="Carriers" subtitle="Partners of type carrier; add new carriers under Partners" />
    <ErrorSummary :error="error" />
    <div class="card">
        <p v-if="carriers.length === 0" class="py-8 text-center text-sm text-ink-muted">No carriers yet.</p>
        <ul class="divide-y divide-line">
            <li v-for="c in carriers" :key="c.id" class="grid gap-2 py-4 md:grid-cols-[14rem_1fr_auto] md:items-end">
                <div>
                    <p class="font-semibold">{{ c.name }}</p>
                    <p class="text-sm text-ink-muted">{{ c.code }}</p>
                </div>
                <div>
                    <label :for="`tpl-${c.id}`" class="label">Tracking link</label>
                    <input :id="`tpl-${c.id}`" v-model="templates[c.id]" class="input" placeholder="https://carrier.example/track/{tracking}" :disabled="!can('partners.carrier.manage')">
                </div>
                <UiButton v-if="can('partners.carrier.manage')" variant="primary" @click="save(c.id)">Save</UiButton>
            </li>
        </ul>
    </div>
</template>
