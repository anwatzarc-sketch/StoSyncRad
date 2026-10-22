import '../css/app.css';
import { createApp, h, type DefineComponent } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import AppLayout from './layouts/AppLayout.vue';
import AuthLayout from './layouts/AuthLayout.vue';
import SetupLayout from './layouts/SetupLayout.vue';
import PortalLayout from './layouts/PortalLayout.vue';
import PublicLayout from './layouts/PublicLayout.vue';
import MarketingLayout from './layouts/MarketingLayout.vue';
import { configureFormatting } from './lib/format';
import { registerServiceWorker } from './lib/pwa';
import type { SharedProps } from './types/shared';

const pages = import.meta.glob<DefineComponent>('./pages/**/*.vue');
const nonce = document.querySelector<HTMLMetaElement>('meta[name="csp-nonce"]')?.content;

createInertiaApp({
    title: (title, page) => {
        const brand = (page.props as unknown as SharedProps).brand?.app_name ?? '';

        return title ? `${title} · ${brand}` : brand;
    },
    resolve: async (name) => {
        const loader = pages[`./pages/${name}.vue`];
        if (!loader) {
            throw new Error(`Unknown page: ${name}`);
        }
        const module = (await loader()) as unknown as { default: DefineComponent };

        return module.default;
    },
    layout: (name) => (name.startsWith('Setup/') ? SetupLayout : name.startsWith('Auth/') ? AuthLayout : name.startsWith('Marketing/') ? MarketingLayout
        : name.startsWith('Portal/Public/') ? PublicLayout : name.startsWith('Portal/') ? PortalLayout : AppLayout),
    setup({ el, App, props, plugin }) {
        configureFormatting((props.initialPage.props as unknown as SharedProps).locale);
        createApp({ render: () => h(App, props) }).use(plugin).mount(el);
    },
    progress: { color: 'var(--ssr-accent)', showSpinner: false },
    nonce,
});

registerServiceWorker();
