{{-- v1.1 · 5. modul — a HOSZTOLT feliratkozó űrlap (`GET /f/{slug}`), iframe-be is ágyazható.

     Szkript NINCS: a time-trap ideje a szerver által aláírt RENDERELÉSI TOKENBŐL (`_rt`) jön,
     nem a böngésző óraméréséből. A honeypot mező neve űrlaponként generált, és a képernyőn
     kívülre tolt (nem `type="hidden"`: azt a botok kiszűrik). Süti nincs. --}}
@extends('hosted.layout')

@section('title', $title)
@section('brand', $workspaceName)

@section('content')
    <h1>{{ $title }}</h1>
    @if ($intro !== '')
        <p class="hp-muted">{{ $intro }}</p>
    @endif

    <form class="hp-form" method="post" action="{{ $action }}" data-testid="hosted-form">
        <div class="hp-fields">
            <div class="hp-field">
                <label for="hp-email">{{ __('studio.hosted.email') }}</label>
                <input id="hp-email" type="email" name="email" required autocomplete="email" maxlength="200">
            </div>

            @foreach ($fields as $field)
                <div class="hp-field">
                    <label for="hp-{{ $field['name'] }}">{{ $field['label'] }}@if ($field['required']) *@endif</label>
                    <input
                        id="hp-{{ $field['name'] }}"
                        type="{{ $field['input'] }}"
                        name="{{ $field['name'] }}"
                        maxlength="200"
                        @if ($field['required']) required @endif
                        @if ($field['name'] === 'first_name') autocomplete="given-name" @elseif ($field['name'] === 'last_name') autocomplete="family-name" @endif
                    >
                </div>
            @endforeach
        </div>

        <div class="hp-trap" aria-hidden="true">
            <label for="hp-trap">{{ __('studio.hosted.trap') }}</label>
            <input id="hp-trap" type="text" name="{{ $honeypot }}" tabindex="-1" autocomplete="off">
        </div>
        <input type="hidden" name="_rt" value="{{ $renderToken }}">

        <label class="hp-check">
            <input type="checkbox" name="consent" value="1" required>
            <span>{{ $consentText }}</span>
        </label>

        <p class="hp-muted">{{ __('studio.hosted.doiNotice') }}</p>

        <div class="hp-actions">
            <button type="submit" class="hp-button" data-testid="hosted-submit">{{ $button }}</button>
        </div>
    </form>
@endsection
