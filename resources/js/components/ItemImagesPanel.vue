<script setup lang="ts">
import { onMounted, ref } from 'vue';
import UiButton from '@/components/ui/UiButton.vue';
import ErrorSummary from '@/components/ui/ErrorSummary.vue';
import { api, del, get, post } from '@/lib/api';
import { notify } from '@/lib/toast';

/**
 * Product photos of an item: shown in the catalogue and the customer portal (the primary photo first).
 * The server checks the file content (JPG, PNG or WebP only), size and number of photos.
 */
const props = defineProps<{ itemId: string; canEdit: boolean }>();

interface ItemImage {
    id: string;
    url: string;
    is_primary: boolean;
}

const images = ref<ItemImage[]>([]);
const error = ref<unknown>(null);
const busy = ref<string | null>(null);
const input = ref<HTMLInputElement | null>(null);

async function load() {
    try {
        images.value = (await get(`/api/v1/items/${props.itemId}/images`)).data;
    } catch (e) {
        error.value = e;
    }
}

onMounted(load);

async function upload(event: Event) {
    const files = Array.from((event.target as HTMLInputElement).files ?? []);
    if (!files.length) {
        return;
    }
    busy.value = 'upload';
    error.value = null;
    try {
        for (const file of files) {
            const data = new FormData();
            data.append('image', file);
            await api(`/api/v1/items/${props.itemId}/images`, { method: 'POST', form: data });
        }
        notify(files.length === 1 ? 'Photo added.' : `${files.length} photos added.`);
    } catch (e) {
        error.value = e;
    } finally {
        busy.value = null;
        if (input.value) {
            input.value.value = '';
        }
        await load();
    }
}

async function makePrimary(image: ItemImage) {
    busy.value = image.id;
    try {
        images.value = (await post(`/api/v1/items/${props.itemId}/images/${image.id}/primary`)).data;
    } catch (e) {
        error.value = e;
    } finally {
        busy.value = null;
    }
}

async function remove(image: ItemImage) {
    if (!window.confirm('Delete this photo?')) {
        return;
    }
    busy.value = image.id;
    try {
        images.value = (await del(`/api/v1/items/${props.itemId}/images/${image.id}`)).data;
        notify('Photo deleted.');
    } catch (e) {
        error.value = e;
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <section class="card">
        <div class="mb-3 flex flex-wrap items-center gap-3">
            <h2 class="flex-1 text-lg font-semibold">Photos</h2>
            <template v-if="canEdit">
                <input ref="input" type="file" accept="image/jpeg,image/png,image/webp" multiple class="sr-only" id="item-photo-input" @change="upload" />
                <UiButton icon="camera" :loading="busy === 'upload'" @click="input?.click()">Add photos</UiButton>
            </template>
        </div>
        <ErrorSummary :error="error" />
        <p v-if="!images.length" class="text-sm text-ink-muted">No photos yet. The primary photo is shown in the catalogue and to customers in the portal.</p>
        <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            <li v-for="image in images" :key="image.id" class="overflow-hidden rounded-brand border border-line">
                <img :src="image.url" alt="Product photo" class="aspect-square w-full bg-surface-muted object-contain" loading="lazy" />
                <div class="flex flex-wrap items-center gap-1 p-2">
                    <span v-if="image.is_primary" class="rounded-full bg-accent/10 px-2 py-0.5 text-xs font-semibold text-accent">Primary</span>
                    <template v-if="canEdit">
                        <button v-if="!image.is_primary" type="button" class="btn btn-ghost min-h-8 px-2 text-xs" :disabled="busy === image.id" @click="makePrimary(image)">Make primary</button>
                        <button type="button" class="btn btn-ghost ml-auto min-h-8 px-2 text-xs text-danger" :disabled="busy === image.id" @click="remove(image)">Delete</button>
                    </template>
                </div>
            </li>
        </ul>
    </section>
</template>
