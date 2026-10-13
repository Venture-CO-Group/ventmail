import { createApp } from 'vue';
import { createI18n } from 'vue-i18n';
import { describe, expect, it } from 'vitest';
import hu from '../../../resources/js/locales/hu.json';
import ru from '../../../resources/js/locales/ru.json';
import { useCodes } from '../../../resources/js/composables/useCodes';

function labeller(locale: 'hu' | 'ru') {
    let label!: ReturnType<typeof useCodes>;
    const app = createApp({
        setup() {
            label = useCodes();

            return () => null;
        },
    });

    app.use(createI18n({ legacy: false, locale, messages: { hu, ru } }));
    app.mount(document.createElement('div'));

    return label;
}

describe('useCodes — gépi kód → címke (D292)', () => {
    it('a kapu, az ok, az esemény és a DKIM-hiba fordított címkét kap', () => {
        const label = labeller('hu');

        expect(label('gate', 'sending_mode')).toBe('küldési mód');
        expect(label('reason', 'SENDING_RESTRICTED')).toBe('korlátozott küldési mód');
        expect(label('event', 'delivered')).toBe('kézbesítve');
        expect(label('dkim', 'no_signing_key')).toBe('nincs aláírókulcs');
        expect(label('contactStatus', 'pending')).toBe('megerősítésre vár');
        expect(label('trigger', 'api_event')).not.toBe('api_event');
    });

    it('ismeretlen kódnál maga a kód, üresnél gondolatjel (biztonságos visszaesés)', () => {
        const label = labeller('ru');

        expect(label('gate', 'uj_kapu_2027')).toBe('uj_kapu_2027');
        expect(label('event', null)).toBe('—');
        expect(label('event', '')).toBe('—');
    });
});
