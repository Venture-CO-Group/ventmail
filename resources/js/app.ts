import { createInertiaApp } from '@inertiajs/vue3';
import '@venture/design/styles.css';
import VentureUI from '@venture/ui';
import '@venture/ui/style.css';
import { createPinia } from 'pinia';
import { createApp, h, type DefineComponent } from 'vue';

createInertiaApp({
    title: (title) => (title ? `${title} · ventMail` : 'ventMail'),
    resolve: (name) => {
        const pages = import.meta.glob<DefineComponent>('./Pages/**/*.vue', { eager: true });

        return pages[`./Pages/${name}.vue`];
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(createPinia())
            .use(VentureUI)
            .mount(el);
    },
});
