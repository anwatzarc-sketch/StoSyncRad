import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import type { SharedProps } from '@/types/shared';

/** Typed access to the shared Inertia props (auth, brand, nav, locale, features, setup). */
export function useShared() {
    const page = usePage();
    const props = computed(() => page.props as unknown as SharedProps);

    return {
        props,
        brand: computed(() => props.value.brand),
        auth: computed(() => props.value.auth),
        nav: computed(() => props.value.nav ?? []),
        features: computed(() => props.value.features ?? {}),
        /** Display-only hint; the server decides every request. */
        can: (permission: string) => (props.value.auth?.permissions as string[] | undefined)?.includes(permission) ?? false,
        feature: (flag: string) => props.value.features?.[flag] === true,
    };
}
