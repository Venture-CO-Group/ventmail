{{-- MEGERŐSÍTŐ OLDAL — a GET nem ír.

     A levélben lévő linkeket a védelmi átjárók (Outlook SafeLinks, vírusszűrő proxy,
     kliens-előtöltés) végiglátogatják. Írós GET-tel egy SZKENNER teremtett volna jogalapot,
     és a bizonyítékba az ő IP-je és user-agentje került volna az érintett
     hozzájárulásaként — vagyis a double opt-in de facto single opt-inné vált volna minden
     szkennelő átjáró mögötti címzettnél. --}}
<!doctype html>
<html lang="hu">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Feliratkozás megerősítése</title>
</head>
<body>
    <main>
        <h1>Feliratkozás megerősítése</h1>
        <p>Erősítsd meg, hogy szeretnél levelet kapni tőlünk.</p>
        <form method="post" action="{{ route('consent.confirm.store', ['token' => $token]) }}">
            <button type="submit">Igen, megerősítem</button>
        </form>
        <p><small>Ha nem te kérted, egyszerűen zárd be ezt az oldalt — megerősítés nélkül
           nem küldünk semmit.</small></p>
    </main>
</body>
</html>
