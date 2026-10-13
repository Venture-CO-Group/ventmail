{{-- UGYANAZ az oldal minden kimenetnél (érvényes, lejárt, visszavont token): a különbség
     orákulum lenne arra, hogy egy cím szerepel-e a rendszerben. --}}
@extends('hosted.layout')

@section('title', __('studio.hosted.confirmedTitle'))

@section('content')
    <h1>{{ __('studio.hosted.confirmedTitle') }}</h1>
    <p>{{ __('studio.hosted.confirmedBody') }}</p>
    <p class="hp-muted">{{ __('studio.hosted.confirmedHint') }}</p>
@endsection
