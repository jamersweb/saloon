import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';

const appName = import.meta.env.VITE_APP_NAME || 'Vina Management System';

const shouldPreserveScroll = (href, options) => {
    if (options.preserveScroll !== undefined) {
        return options.preserveScroll;
    }

    const method = (options.method || 'get').toLowerCase();

    if (method !== 'get') {
        return true;
    }

    try {
        return new URL(href, window.location.href).pathname === window.location.pathname;
    } catch (_error) {
        return false;
    }
};

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    defaults: {
        visitOptions: (href, options) => ({
            ...options,
            preserveScroll: shouldPreserveScroll(href, options),
        }),
    },
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.jsx`,
            import.meta.glob('./Pages/**/*.jsx'),
        ),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(<App {...props} />);
    },
    progress: {
        color: '#C87374',
    },
});
