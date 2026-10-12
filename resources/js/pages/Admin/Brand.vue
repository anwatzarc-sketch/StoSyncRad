<script setup lang="ts">
import { onMounted, ref } from 'vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import BrandEditor from '@/components/BrandEditor.vue';
import { get, post } from '@/lib/api';
import { loadEnums } from '@/lib/meta';
import { formatDateTime } from '@/lib/format';
import { useShared } from '@/composables/useShared';
import type { BrandForm } from '@/types/shared';

/** Re-brand without a rebuild (Chapter 03 §3.5): every save creates and activates a new kit version. */
const { can } = useShared();
const form = ref<BrandForm | null>(null);
const kits = ref<Record<string, any>[]>([]);
const busy = ref(false);
const error = ref<unknown>(null);

onMounted(async () => {
    await loadEnums().catch(() => undefined);
    try {
        const d = (await get('/api/v1/admin/brand')).data;
        kits.value = d.kits ?? [];
        form.value = {
            app_name: d.app_name, app_short_name: d.app_short_name, tagline: d.tagline, legal_footer: d.legal_footer, support_email: d.support_email,
            support_phone: d.support_phone, support_url: d.support_url,
            theme: { colors: { ...d.theme.colors }, font: d.theme.font, radius: d.theme.radius, density: d.theme.density },
            assets: {}, contrast_override_reason: null,
        };
    } catch (e) {
        error.value = e;
    }
});

async function activate() {
    busy.value = true;
    error.value = null;
    try {
        await post('/api/v1/admin/brand', form.value);
        window.location.reload();   // theme.css, manifest and icons now come from the new kit
    } catch (e) {
        error.value = e;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <PageHeader title="Brand" subtitle="Names, colours, logos and icons — applied everywhere without rebuilding the app">
        <template #actions>
            <UiButton v-if="can('platform.brand.manage')" variant="primary" :loading="busy" :disabled="!form" @click="activate">Save & activate</UiButton>
        </template>
    </PageHeader>
    <ErrorSummary :error="error" />
    <div v-if="form" class="card">
        <BrandEditor v-model="form" upload-url="/api/v1/admin/brand/assets" />
    </div>
    <section v-if="kits.length" class="card mt-6">
        <h2 class="mb-2 font-semibold">Versions</h2>
        <ul class="divide-y divide-line text-sm">
            <li v-for="k in kits" :key="k.id" class="flex justify-between py-2">
                <span>Version {{ k.version }} <strong v-if="k.is_active" class="text-success">(active)</strong></span>
                <span class="text-ink-muted">{{ formatDateTime(k.activated_at ?? k.created_at) }}</span>
            </li>
        </ul>
    </section>
</template>
