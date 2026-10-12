<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import EnumSelect from './ui/EnumSelect.vue';
import type { BrandForm } from '@/types/shared';
import { api } from '@/lib/api';
import { notify } from '@/lib/toast';

/**
 * Brand kit form + live preview (Chapter 03 §3.5, Chapter 12 §12.5): names, colour tokens with a
 * WCAG AA contrast check, font/radius/density, and asset uploads. Used by the setup wizard and by
 * Administration; `uploadUrl` differs (setup session vs. admin permission).
 */

const props = defineProps<{ modelValue: BrandForm; uploadUrl: string }>();
const emit = defineEmits<{ 'update:modelValue': [value: BrandForm] }>();

const TOKENS: Array<[string, string]> = [
    ['brand', 'Brand'], ['brand_contrast', 'Text on brand'], ['accent', 'Accent'], ['accent_contrast', 'Text on accent'], ['surface', 'Background'],
    ['surface_muted', 'Muted background'], ['text', 'Text'], ['text_muted', 'Muted text'], ['border', 'Borders'], ['focus', 'Focus ring'],
    ['success', 'Success'], ['warning', 'Warning'], ['danger', 'Danger'], ['info', 'Info'], ['nav_background', 'Navigation'], ['nav_text', 'Navigation text'],
];
const PAIRS: Array<[string, string]> = [['brand_contrast', 'brand'], ['accent_contrast', 'accent'], ['text', 'surface'], ['text_muted', 'surface'], ['nav_text', 'nav_background']];
const ASSETS: Array<[string, string]> = [['logo_light', 'Logo (light backgrounds)'], ['logo_dark', 'Logo (dark backgrounds)'], ['logo_mark', 'Mark / small logo'],
    ['favicon_source', 'Favicon'], ['pwa_icon_source', 'App icon (square, ≥ 512 px)'], ['login_background', 'Sign-in background'],
    ['letterhead_header', 'Document letterhead header'], ['letterhead_footer', 'Document letterhead footer']];

const uploading = reactive<Record<string, boolean>>({});
const previews = reactive<Record<string, string>>({});
const form = computed(() => props.modelValue);

function set<K extends keyof BrandForm>(key: K, value: BrandForm[K]) {
    emit('update:modelValue', { ...props.modelValue, [key]: value });
}
function setColor(token: string, value: string) {
    set('theme', { ...form.value.theme, colors: { ...form.value.theme.colors, [token]: value } });
}
function setTheme(key: 'font' | 'radius' | 'density', value: string | null) {
    set('theme', { ...form.value.theme, [key]: value ?? form.value.theme[key] });
}

function luminance(hex: string): number {
    const c = [1, 3, 5].map((i) => Number.parseInt(hex.slice(i, i + 2), 16) / 255).map((v) => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4));

    return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
}
function ratio(a: string, b: string): number {
    const [l1, l2] = [luminance(a), luminance(b)];

    return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
}
const contrast = computed(() => PAIRS.filter(([fg, bg]) => form.value.theme.colors[fg] && form.value.theme.colors[bg])
    .map(([fg, bg]) => ({ fg, bg, ratio: ratio(form.value.theme.colors[fg], form.value.theme.colors[bg]) })));
const failing = computed(() => contrast.value.filter((c) => c.ratio < 4.5));

const previewStyle = computed(() => Object.fromEntries(Object.entries(form.value.theme.colors).map(([k, v]) => [`--p-${k.replace(/_/g, '-')}`, v])));

const fileInput = ref<HTMLInputElement | null>(null);
async function upload(kind: string, event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) {
        return;
    }
    uploading[kind] = true;
    try {
        const data = new FormData();
        data.append('kind', kind);
        data.append('file', file);
        const r = await api(props.uploadUrl, { method: 'POST', form: data });
        set('assets', { ...form.value.assets, [kind]: r.data.upload_id });
        previews[kind] = URL.createObjectURL(file);
        notify('Uploaded — it becomes active when you save the brand kit.', 'info');
    } catch (e) {
        notify((e as Error).message, 'error');
    } finally {
        uploading[kind] = false;
    }
}
</script>

<template>
    <div class="grid gap-6 xl:grid-cols-[1fr_22rem]">
        <div class="space-y-6">
            <section class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="b-name" class="label">Application name <span class="text-danger">*</span></label>
                    <input id="b-name" class="input" maxlength="80" :value="form.app_name" @input="set('app_name', ($event.target as HTMLInputElement).value)">
                </div>
                <div>
                    <label for="b-short" class="label">Short name (≤ 12) <span class="text-danger">*</span></label>
                    <input id="b-short" class="input" maxlength="12" :value="form.app_short_name" @input="set('app_short_name', ($event.target as HTMLInputElement).value)">
                </div>
                <div class="sm:col-span-2">
                    <label for="b-tagline" class="label">Tagline</label>
                    <input id="b-tagline" class="input" :value="form.tagline ?? ''" @input="set('tagline', ($event.target as HTMLInputElement).value || null)">
                </div>
                <div>
                    <label for="b-email" class="label">Support email</label>
                    <input id="b-email" type="email" class="input" :value="form.support_email ?? ''" @input="set('support_email', ($event.target as HTMLInputElement).value || null)">
                </div>
                <div>
                    <label for="b-phone" class="label">Support phone</label>
                    <input id="b-phone" type="tel" class="input" :value="form.support_phone ?? ''" @input="set('support_phone', ($event.target as HTMLInputElement).value || null)">
                </div>
                <div class="sm:col-span-2">
                    <label for="b-url" class="label">Support site</label>
                    <input id="b-url" type="url" class="input" :value="form.support_url ?? ''" @input="set('support_url', ($event.target as HTMLInputElement).value || null)">
                </div>
                <div class="sm:col-span-2">
                    <label for="b-legal" class="label">Legal footer</label>
                    <textarea id="b-legal" class="input min-h-20" :value="form.legal_footer ?? ''" @input="set('legal_footer', ($event.target as HTMLTextAreaElement).value || null)" />
                </div>
            </section>

            <section>
                <h3 class="mb-2 font-semibold">Colours</h3>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <label v-for="[token, label] in TOKENS" :key="token" class="flex items-center gap-2 text-sm">
                        <input type="color" class="h-11 w-12 cursor-pointer rounded border border-line bg-surface" :value="form.theme.colors[token] ?? '#000000'"
                               @input="setColor(token, ($event.target as HTMLInputElement).value)">
                        {{ label }}
                    </label>
                </div>
                <ul class="mt-3 space-y-1 text-sm">
                    <li v-for="c in contrast" :key="c.fg" :class="c.ratio < 4.5 ? 'text-danger' : 'text-ink-muted'">
                        {{ c.ratio < 4.5 ? '✕' : '✓' }} {{ c.fg }} on {{ c.bg }}: {{ c.ratio.toFixed(2) }}:1 {{ c.ratio < 4.5 ? '(below WCAG AA 4.5:1)' : '' }}
                    </li>
                </ul>
                <div v-if="failing.length" class="mt-3">
                    <label for="b-override" class="label">Reason to accept the low contrast (recorded)</label>
                    <input id="b-override" class="input" :value="form.contrast_override_reason ?? ''"
                           @input="set('contrast_override_reason', ($event.target as HTMLInputElement).value || null)">
                </div>
            </section>

            <section class="grid gap-4 sm:grid-cols-3">
                <div>
                    <label for="b-font" class="label">Font</label>
                    <EnumSelect id="b-font" enum-code="font_family" :model-value="form.theme.font" @update:model-value="setTheme('font', $event as string | null)" />
                </div>
                <div>
                    <label for="b-radius" class="label">Corners</label>
                    <EnumSelect id="b-radius" enum-code="theme_radius" :model-value="form.theme.radius" @update:model-value="setTheme('radius', $event as string | null)" />
                </div>
                <div>
                    <label for="b-density" class="label">Density</label>
                    <EnumSelect id="b-density" enum-code="theme_density" :model-value="form.theme.density" @update:model-value="setTheme('density', $event as string | null)" />
                </div>
            </section>

            <section>
                <h3 class="mb-2 font-semibold">Images</h3>
                <ul class="grid gap-3 sm:grid-cols-2">
                    <li v-for="[kind, label] in ASSETS" :key="kind" class="rounded-brand border border-line p-3">
                        <label :for="`asset-${kind}`" class="label">{{ label }}</label>
                        <img v-if="previews[kind]" :src="previews[kind]" alt="" class="mb-2 max-h-16 object-contain">
                        <input :id="`asset-${kind}`" ref="fileInput" type="file" accept="image/png,image/jpeg,image/webp,image/svg+xml" class="text-sm"
                               :disabled="uploading[kind]" @change="upload(kind, $event)">
                        <p v-if="form.assets[kind]" class="help">Ready to activate.</p>
                    </li>
                </ul>
            </section>
        </div>

        <aside class="space-y-3" aria-label="Preview">
            <p class="label">Preview</p>
            <div class="overflow-hidden rounded-xl border border-line shadow" :style="previewStyle">
                <div class="flex items-center gap-2 px-3 py-3" style="background: var(--p-nav-background); color: var(--p-nav-text)">
                    <img v-if="previews.logo_mark" :src="previews.logo_mark" alt="" class="h-6 w-6 object-contain">
                    <strong>{{ form.app_name || 'Application' }}</strong>
                </div>
                <div class="space-y-3 p-4" style="background: var(--p-surface); color: var(--p-text)">
                    <p class="text-lg font-bold">{{ form.tagline || 'Stock at a glance' }}</p>
                    <p style="color: var(--p-text-muted)" class="text-sm">Muted helper text</p>
                    <div class="flex gap-2">
                        <span class="rounded px-3 py-2 text-sm font-semibold" style="background: var(--p-brand); color: var(--p-brand-contrast)">Primary</span>
                        <span class="rounded px-3 py-2 text-sm font-semibold" style="background: var(--p-accent); color: var(--p-accent-contrast)">Post</span>
                    </div>
                    <div class="flex gap-2 text-xs font-semibold">
                        <span style="color: var(--p-success)">Posted</span><span style="color: var(--p-warning)">Pending</span><span style="color: var(--p-danger)">Rejected</span>
                    </div>
                </div>
            </div>
        </aside>
    </div>
</template>
