<!DOCTYPE html>
{{-- Sötét téma kényszerítve: a ventMail egyetlen, "liquid glass" sötét arculatot visel (mint a ventSSO). --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">

    <title inertia>{{ config('app.name', 'ventMail') }}</title>

    @include('partials.brand-head')
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
