{{-- MEGERŐSÍTŐ oldal a levélből érkező kattintáshoz. Az oldal NEM ír, a gomb POST-ol.

     A levélben lévő linkeket a védelmi átjárók (Outlook SafeLinks, vírusszűrő proxy,
     kliens-előtöltés) végiglátogatják. Írós GET-tel egy SZKENNER teremtett volna jogalapot,
     és a bizonyítékba az ő IP-je és user-agentje került volna az érintett
     hozzájárulásaként — vagyis a double opt-in de facto single opt-inné vált volna minden
     szkennelő átjáró mögötti címzettnél. --}}
@extends('hosted.layout')

@section('title', __('studio.hosted.confirmTitle'))

@section('content')
    <h1>{{ __('studio.hosted.confirmTitle') }}</h1>
    <p>{{ __('studio.hosted.confirmBody') }}</p>
    <form class="hp-form" method="post" action="{{ route('consent.confirm.store', ['token' => $token]) }}">
        <div class="hp-actions">
            <button type="submit" class="hp-button" data-testid="confirm-submit">{{ __('studio.hosted.confirmAction') }}</button>
        </div>
    </form>
    <p class="hp-muted">{{ __('studio.hosted.confirmIgnore') }}</p>
@endsection
