@php
    $landingUrl = 'https://'.config('app.landing_host').'/';
    $appUrl = rtrim((string) config('app.url'), '/');
@endphp
<!DOCTYPE html>
{{-- Statikus termékoldal az apexen (ventmail.app). NEM landing-page-builder: az a ventStudio képessége.
     Nincs Inertia, nincs app-JS; indexelhető (az app-oldalak noindex-ek maradnak). --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="dark">
<head>
    @include('partials.ventcookie-loader')
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ __('landing.meta.title') }}</title>
    <meta name="description" content="{{ __('landing.meta.description') }}">
    <link rel="canonical" href="{{ $landingUrl }}">

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="ventMail">
    <meta property="og:title" content="{{ __('landing.meta.title') }}">
    <meta property="og:description" content="{{ __('landing.meta.description') }}">
    <meta property="og:url" content="{{ $landingUrl }}">
    <meta property="og:image" content="{{ $landingUrl }}brand/og-image.png">
    <meta name="twitter:card" content="summary_large_image">

    @include('partials.brand-head')
    @vite(['resources/css/landing.css'])
</head>
<body class="landing">
    <a class="landing__skip" href="#main">{{ __('landing.skip') }}</a>

    <div class="landing__blobs" aria-hidden="true">
        <span class="landing__blob landing__blob--a"></span>
        <span class="landing__blob landing__blob--b"></span>
        <span class="landing__blob landing__blob--c"></span>
    </div>

    <header class="landing__header">
        <a class="brand-logo" href="/" aria-label="ventMail">
            <img class="brand-logo__mark" src="/brand/logomark.svg" alt="">
            <span class="brand-logo__word vt-num">vent<strong>Mail</strong></span>
        </a>
        <a class="landing__btn landing__btn--ghost" href="{{ $appUrl }}">{{ __('landing.nav.login') }}</a>
    </header>

    <main id="main">
        <section class="landing__hero">
            <p class="vt-overline">{{ __('landing.hero.eyebrow') }}</p>
            <h1 class="landing__title">{{ __('landing.hero.title') }}</h1>
            <p class="landing__lede">{{ __('landing.hero.lede') }}</p>
            <div class="landing__cta">
                <a class="landing__btn" href="{{ $appUrl }}">{{ __('landing.nav.login') }}</a>
            </div>
        </section>

        <section class="landing__pillars" id="features">
            @foreach (['platform', 'studio'] as $pillar)
                <article class="vt-glass landing__card">
                    <h2 class="landing__h2">{{ __("landing.$pillar.title") }}</h2>
                    <p class="landing__muted">{{ __("landing.$pillar.lede") }}</p>
                    <ul class="landing__list">
                        @foreach (trans("landing.$pillar.items") as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ul>
                </article>
            @endforeach
        </section>

        <section class="landing__engine">
            <h2 class="landing__h2">{{ __('landing.engine.title') }}</h2>
            <p class="landing__lede">{{ __('landing.engine.text') }}</p>
        </section>

        <section class="landing__deliver">
            <h2 class="landing__h2">{{ __('landing.deliver.title') }}</h2>
            <p class="landing__lede">{{ __('landing.deliver.lede') }}</p>
            <ul class="landing__grid">
                @foreach (trans('landing.deliver.items') as $item)
                    <li class="vt-glass landing__tile">
                        <h3 class="landing__h3">{{ $item['title'] }}</h3>
                        <p class="landing__muted">{{ $item['text'] }}</p>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="landing__eu">
            <h2 class="landing__h3">{{ __('landing.eu.title') }}</h2>
            <p class="landing__muted">{{ __('landing.eu.text') }}</p>
        </section>
    </main>

    <footer class="landing__footer">
        <nav class="landing__legal" aria-label="{{ __('landing.footer.nav') }}">
            <a href="https://sso.ventureco.app/terms" rel="noopener">{{ __('landing.footer.terms') }}</a>
            <a href="https://sso.ventureco.app/privacy-policy" rel="noopener">{{ __('landing.footer.privacy') }}</a>
            @if (config('ventcookie.domain_token') !== '')
                {{-- Inline kezelő nincs (CSP script-src 'self'): a kötést a /landing/cookie-settings.js végzi. --}}
                <a href="#cookie-settings" data-vc-open-preferences>{{ __('landing.footer.cookies') }}</a>
                <script src="/landing/cookie-settings.js" defer></script>
            @endif
        </nav>
        <div class="landing__venture">
            <img src="/brand/venture-logo-light.svg" alt="Venture CO Group" width="51" height="24">
            <p class="landing__copy">&copy; {{ date('Y') }} Venture CO Group</p>
        </div>
    </footer>
</body>
</html>
