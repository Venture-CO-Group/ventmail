import { usePage } from '@inertiajs/vue3';
import { watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { loadLocaleMessages } from '../i18n';

/**
 * A KLIENS NYELVE KÖVETI A SZERVERT.
 *
 * A szerver (`SetLocale`) dönti el a kérés nyelvét, a kliens szótára viszont csak bootkor
 * töltődik be. Ha a nyelv váltás nélkül a nyelvválasztón át változik (pl. a profil mentése),
 * a megosztott `locale` prop eltér a kliens nyelvétől — ilyenkor betöltjük a szótárt és
 * átváltunk, különben a felület a régi nyelven maradna a szerver-szövegek mellett.
 */
export function useLocaleSync(): void {
    const page = usePage<{ locale: string }>();
    const { locale, setLocaleMessage } = useI18n({ useScope: 'global' });

    watch(
        () => page.props.locale,
        async (code) => {
            if (!code || code === locale.value) return;

            setLocaleMessage(code, (await loadLocaleMessages(code)) as never);
            locale.value = code;
        },
    );
}
