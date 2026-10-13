import { createApp, type App } from 'vue';
import { afterEach } from 'vitest';

/*
 | Egy composable futtatása VALÓDI komponens-példányban (a `onBeforeUnmount` / `provide`
 | csak így él), külső tesztkönyvtár nélkül. Minden teszt után lebontja a még élő példányokat
 | — különben egy korábbi teszt `window`-figyelője átszivárogna a következőbe.
 */
const mounted = new Set<App>();

afterEach(() => {
    mounted.forEach((app) => app.unmount());
    mounted.clear();
});

export function withSetup<T>(composable: () => T): { result: T; app: App; unmount: () => void } {
    let result!: T;
    const app = createApp({
        setup() {
            result = composable();

            return () => null;
        },
    });

    app.mount(document.createElement('div'));
    mounted.add(app);

    return {
        result,
        app,
        unmount: () => {
            if (mounted.delete(app)) app.unmount();
        },
    };
}
