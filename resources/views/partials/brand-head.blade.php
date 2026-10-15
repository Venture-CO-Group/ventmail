{{-- Favicon-készlet + manifest — az app és a landing közös feje. A theme-color a sötét --vt-bg token értéke. --}}
<link rel="icon" href="/favicon.ico" sizes="48x48">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="manifest" href="/site.webmanifest">
{{-- Egyetlen forrás: a sötét --vt-bg token értéke (@venture/design). Ha a token változik, ez és a site.webmanifest együtt frissítendő. --}}
<meta name="theme-color" content="#080B16">
{{-- Betűk: SAJÁT originről (@venture/design/fonts.css, a CSS-belépőkben) — harmadik fél betűszerverére
     nem megy látogatói IP. A két fő vágás (törzs + címsor, latin) preload, hogy a swap ne ugorjon. --}}
@foreach (\App\Support\FontPreload::urls() as $fontUrl)
<link rel="preload" as="font" type="font/woff2" href="{{ $fontUrl }}" crossorigin>
@endforeach
