{{-- PREFERENCE CENTER (v1.1: profil-mezőkkel). A levélből nyílik meg, gyakran mobilon; nem
     tartozik a bejelentkezett felülethez — szkript nincs, a stílus a token-alapú `hosted.css`.

     Csak a kontakt SAJÁT adatai látszanak (a saját listái és a saját mezőértékei) — a
     tenant teljes listastruktúrája vagy más személyes adat nem. A GET NEM ÍR: a védelmi
     átjárók link-előtöltése különben csendben módosítaná a beállításokat. --}}
@extends('hosted.layout')

@section('title', __('studio.hosted.preferencesTitle'))

@section('content')
    <h1>{{ __('studio.hosted.preferencesTitle') }}</h1>

    <form class="hp-form" method="post" action="{{ route('preferences.update', ['token' => $token]) }}" data-testid="preferences-form">
        @if ($lists === [] && $profile === [])
            <p>{{ __('studio.hosted.preferencesInvalid') }}</p>
        @else
            @if ($lists !== [])
                <fieldset class="hp-section">
                    <legend class="hp-legend">{{ __('studio.hosted.preferencesLists') }}</legend>
                    <ul class="hp-list">
                        @foreach ($lists as $list)
                            <li>
                                <label class="hp-check">
                                    <input type="checkbox" name="lists[]" value="{{ $list['uuid'] }}" @checked($list['subscribed'])>
                                    <span>
                                        {{ $list['name'] }}
                                        @if ($list['description'] !== '')
                                            <br><span class="hp-muted">{{ $list['description'] }}</span>
                                        @endif
                                    </span>
                                </label>
                            </li>
                        @endforeach
                    </ul>
                </fieldset>
            @endif

            @if ($profile !== [])
                <fieldset class="hp-section">
                    <legend class="hp-legend">{{ __('studio.hosted.preferencesProfile') }}</legend>
                    @foreach ($profile as $field)
                        <div class="hp-field">
                            <label for="pf-{{ $field['name'] }}">{{ $field['label'] }}</label>
                            <input id="pf-{{ $field['name'] }}" type="{{ $field['input'] }}" name="profile[{{ $field['name'] }}]" value="{{ $field['value'] }}" maxlength="200">
                        </div>
                    @endforeach
                </fieldset>
            @endif

            <div class="hp-actions">
                <button type="submit" class="hp-button" data-testid="preferences-save">{{ __('studio.hosted.preferencesSave') }}</button>
            </div>
        @endif

        <hr class="hp-divider">

        {{-- A TELJES leiratkozás külön, EGY kattintással is elérhető: aki ezt akarja, annak ne
             kelljen pipákat kivenni. Ugyanaz a hatása, mint a one-click (RFC 8058) linknek. --}}
        <div class="hp-actions">
            <button type="submit" name="unsubscribe_all" value="1" class="hp-button hp-button--ghost" data-testid="preferences-unsubscribe-all">
                {{ __('studio.hosted.unsubscribeAll') }}
            </button>
        </div>
        <p class="hp-muted">{{ __('studio.hosted.transactionalNote') }}</p>
    </form>
@endsection
