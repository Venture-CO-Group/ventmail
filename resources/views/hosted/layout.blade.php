{{-- v1.1 · 5. modul — a HOSZTOLT, névtelen oldalak közös váza (űrlap, köszönő oldal,
     megerősítés, preference center). Szkript nincs (a CSP tiltja), a stílus a token-alapú
     `hosted.css`. Nem tartozik a bejelentkezett felülethez: @venture/ui itt nem fut. --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    {{-- A token az URL-ben van: ne szivárogjon ki a hivatkozó fejlécben egy külső oldalra. --}}
    <meta name="referrer" content="no-referrer">
    <title>@yield('title')</title>
    @vite(['resources/css/hosted.css'])
</head>
<body>
    <main class="hp">
        <div class="hp-card">
            @hasSection('brand')<p class="hp-brand">@yield('brand')</p>@endif
            @yield('content')
        </div>
    </main>
</body>
</html>
