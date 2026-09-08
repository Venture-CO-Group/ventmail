{{-- MEGERŐSÍTŐ oldal a levélből érkező kattintáshoz. Szándékosan MINIMÁLIS: ez az oldal a
     levélből nyílik meg, gyakran mobilon, és nem tartozik az alkalmazás bejelentkezett
     felületéhez — ezért nincs benne @venture/ui komponens sem.

     Az oldal NEM ír. A gomb POST-ol: a levélben lévő linkeket a védelmi átjárók (SafeLinks,
     vírusszűrő proxy, kliens-előtöltés) végiglátogatják, és írós GET-tel csendben
     leiratkoztatnák azt, aki soha nem kattintott. --}}
<!doctype html>
<html lang="hu">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    {{-- A token az URL-ben van: ne szivárogjon ki a hivatkozó fejlécben egy külső oldalra. --}}
    <meta name="referrer" content="no-referrer">
    <title>Leiratkozás</title>
</head>
<body>
    <main>
        <h1>Leiratkozás</h1>
        <p>Megerősíted, hogy erre a címre a továbbiakban ne küldjünk marketing levelet?</p>
        <form method="post" action="{{ route('unsubscribe.post', ['token' => $token]) }}">
            <button type="submit">Igen, leiratkozom</button>
        </form>
        <p>A számlákat és a fiókodhoz tartozó értesítéseket ez nem érinti.</p>
    </main>
</body>
</html>
