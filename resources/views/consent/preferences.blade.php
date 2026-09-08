{{-- PREFERENCE CENTER. Szándékosan MINIMÁLIS és keretrendszer-mentes: ez az oldal a
     levélből nyílik meg, gyakran mobilon, és nem tartozik a bejelentkezett felülethez —
     ezért nincs benne @venture/ui komponens sem. --}}
<!doctype html>
<html lang="hu">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Levélbeállítások</title>
</head>
<body>
    <main>
        <h1>Levélbeállítások</h1>

        {{-- A GET NEM ÍR: a védelmi átjárók (SafeLinks, vírusszűrő proxy) link-előtöltése
             különben csendben módosítaná a beállításokat. Minden változás POST. --}}
        <form method="post" action="{{ route('preferences.update', ['token' => $token]) }}">
            @if ($lists === [])
                <p>Ez a link már nem érvényes. Kérj újat a levelünk alján lévő linkkel.</p>
            @else
                <p>Válaszd ki, miről szeretnél levelet kapni:</p>
                <ul>
                    @foreach ($lists as $list)
                        <li>
                            <label>
                                <input type="checkbox" name="lists[]" value="{{ $list['uuid'] }}"
                                       @checked($list['subscribed'])>
                                {{ $list['name'] }}
                            </label>
                            @if ($list['description'] !== '')
                                <br><small>{{ $list['description'] }}</small>
                            @endif
                        </li>
                    @endforeach
                </ul>

                <button type="submit">Beállítások mentése</button>
            @endif

            <hr>

            {{-- A TELJES leiratkozás külön, EGY kattintással is elérhető: aki ezt akarja,
                 annak ne kelljen pipákat kivenni. --}}
            <button type="submit" name="unsubscribe_all" value="1">
                Leiratkozom minden marketing levélről
            </button>
            <p><small>A számlákat és a fiókodhoz tartozó értesítéseket ez nem érinti.</small></p>
        </form>
    </main>
</body>
</html>
