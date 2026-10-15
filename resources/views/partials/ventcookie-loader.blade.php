{{-- ventCookie loader: a <head> ELSŐ scriptje (prior blocking), NEM async/defer. Üres token → semmi. --}}
@if (config('ventcookie.domain_token') !== '')
<script src="{{ config('ventcookie.loader_src') }}" data-vc-token="{{ config('ventcookie.domain_token') }}"@if (config('ventcookie.loader_sri') !== '') integrity="{{ config('ventcookie.loader_sri') }}"@endif crossorigin="anonymous"></script>
@endif
@if (config('ventcookie.site_verification') !== '')
<meta name="ventcookie-verification" content="{{ config('ventcookie.site_verification') }}">
@endif
