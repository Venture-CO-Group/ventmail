import { createInertiaApp } from '@inertiajs/vue3';
import '@venture/design/styles.css';
import VentureUI from '@venture/ui';
import '@venture/ui/style.css';
import { createPinia } from 'pinia';
import { createApp, h, type DefineComponent } from 'vue';
import { createVentmailI18n, loadLocaleMessages } from './i18n';
import './auth.css';
import './brand.css';
import './glow.css';
import './shell.css';
import './nav.css';
import './layout.css';
import './profile.css';

/*
 | A KEZDŐ NYELVET a szerver adja (`SetLocale` middleware → Inertia shared prop). A
 | szótárat még a mount ELŐTT betöltjük: enélkül az első képernyő nyers kulcsokkal
 | villanna fel, és pont az a hiba látszana, amit a kulcs-teljességi teszt tilt.
 */
createInertiaApp({
    title: (title) => (title ? `${title} · ventMail` : 'ventMail'),
    resolve: (name) => {
        const pages = import.meta.glob<DefineComponent>('./Pages/**/*.vue', { eager: true });

        return pages[`./Pages/${name}.vue`];
    },
    setup({ el, App, props, plugin }) {
        const locale = (props.initialPage.props.locale as string) ?? 'en';

        void loadLocaleMessages(locale).then((messages) => {
            createApp({ render: () => h(App, props) })
                .use(plugin)
                .use(createPinia())
                .use(VentureUI)
                .use(createVentmailI18n(locale, messages))
                .mount(el);
        });
    },
});
