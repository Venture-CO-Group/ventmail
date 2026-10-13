{{-- UGYANAZ a válasz botra, ismeretlen űrlapra és érvényes beküldésre (űrlaponként: az űrlap
     saját köszönő szövegével): a különbség orákulum lenne, és a bot-szűrés visszajelzése a
     csapda kikerülésére tanítana. A double opt-in miatt a szöveg MINDIG a megerősítő levélről
     szól — azonnali feliratkozás nincs. --}}
@extends('hosted.layout')

@section('title', $title ?? __('studio.hosted.thanksTitle'))

@section('content')
    <h1 data-testid="hosted-thanks">{{ $title ?? __('studio.hosted.thanksTitle') }}</h1>
    <p>{{ $message ?? __('studio.hosted.thanksBody') }}</p>
    <p class="hp-muted">{{ __('studio.hosted.thanksNoMail') }}</p>
@endsection
