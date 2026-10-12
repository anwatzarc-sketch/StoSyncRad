<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue';
import ModalDialog from './ui/ModalDialog.vue';
import AppIcon from './ui/AppIcon.vue';
import { useShared } from '@/composables/useShared';

/**
 * One barcode input for the whole app (Chapter 12 §12.8): keyboard-wedge scanners (fast keystrokes
 * ending in Enter), camera scanning under `feature.camera_scanning` (BarcodeDetector, ZXing
 * fallback) and manual entry. Scanning only identifies — it never posts anything.
 */
const props = withDefaults(defineProps<{ id: string; modelValue: string; disabled?: boolean; placeholder?: string; autofocus?: boolean }>(),
    { disabled: false, placeholder: 'Scan or type a code', autofocus: false });
const emit = defineEmits<{ 'update:modelValue': [value: string]; scan: [code: string] }>();
const { feature } = useShared();

const cameraOpen = ref(false);
const cameraError = ref<string | null>(null);
const video = ref<HTMLVideoElement | null>(null);
let stopCamera: (() => void) | null = null;

function submit() {
    const code = props.modelValue.trim();
    if (code) {
        emit('scan', code);
    }
}

async function openCamera() {
    cameraOpen.value = true;
    cameraError.value = null;
    await new Promise((r) => setTimeout(r, 50));
    if (!video.value) {
        return;
    }
    try {
        const Detector = (window as any).BarcodeDetector;
        if (Detector) {
            const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
            video.value.srcObject = stream;
            await video.value.play();
            const detector = new Detector();
            let active = true;
            stopCamera = () => {
                active = false;
                stream.getTracks().forEach((t) => t.stop());
            };
            const tick = async () => {
                if (!active || !video.value) {
                    return;
                }
                const found = await detector.detect(video.value).catch(() => []);
                if (found.length > 0) {
                    found[0].rawValue && done(String(found[0].rawValue));

                    return;
                }
                requestAnimationFrame(() => void tick());
            };
            void tick();
        } else {
            const { BrowserMultiFormatReader } = await import('@zxing/browser');
            const reader = new BrowserMultiFormatReader();
            const controls = await reader.decodeFromVideoDevice(undefined, video.value, (result) => {
                if (result) {
                    done(result.getText());
                }
            });
            stopCamera = () => controls.stop();
        }
    } catch (e) {
        cameraError.value = (e as Error).name === 'NotAllowedError' ? 'Camera permission was denied.' : 'The camera is not available on this device.';
    }
}

function done(code: string) {
    closeCamera();
    emit('update:modelValue', code);
    emit('scan', code);
}

function closeCamera() {
    stopCamera?.();
    stopCamera = null;
    cameraOpen.value = false;
}

onBeforeUnmount(closeCamera);
</script>

<template>
    <div class="flex gap-2">
        <input
            :id="id"
            class="input font-mono"
            :value="modelValue"
            :disabled="disabled"
            :placeholder="placeholder"
            :autofocus="autofocus"
            autocomplete="off"
            autocapitalize="off"
            spellcheck="false"
            enterkeyhint="search"
            @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
            @keydown.enter.prevent="submit"
        >
        <button
            v-if="feature('feature.camera_scanning')"
            type="button"
            class="btn btn-secondary px-3"
            :disabled="disabled"
            aria-label="Scan with camera"
            @click="openCamera"
        >
            <AppIcon name="camera" />
        </button>
        <ModalDialog :open="cameraOpen" title="Scan with camera" @close="closeCamera">
            <video ref="video" class="aspect-video w-full rounded-brand bg-black" muted playsinline />
            <p v-if="cameraError" class="error-text">{{ cameraError }}</p>
            <p v-else class="help">Point the camera at the barcode. Nothing is posted by scanning.</p>
        </ModalDialog>
    </div>
</template>
