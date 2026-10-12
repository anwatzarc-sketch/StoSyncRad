import { reactive } from 'vue';

export interface Toast {
    id: number;
    kind: 'success' | 'error' | 'info';
    message: string;
}

let next = 1;
export const toasts = reactive<Toast[]>([]);

export function notify(message: string, kind: Toast['kind'] = 'success', ms = 5000): void {
    const id = next++;
    toasts.push({ id, kind, message });
    if (ms > 0) {
        setTimeout(() => dismiss(id), kind === 'error' ? ms * 2 : ms);
    }
}

export function dismiss(id: number): void {
    const i = toasts.findIndex((t) => t.id === id);
    if (i >= 0) {
        toasts.splice(i, 1);
    }
}
