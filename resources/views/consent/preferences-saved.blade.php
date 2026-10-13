@extends('hosted.layout')

@section('title', __('studio.hosted.savedTitle'))

@section('content')
    <h1 data-testid="preferences-saved">{{ __('studio.hosted.savedTitle') }}</h1>
    <p>{{ __('studio.hosted.savedBody') }}</p>
@endsection
