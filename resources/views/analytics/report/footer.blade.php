{{--
 | A PDF LÁBLÉCE (Gotenberg `footer.html`): külön kontextusban renderelődik, külső erőforrást nem
 | tölt, ezért a riport-CSS (tokenekkel) inline érkezik. Az oldalszámot a Chromium tölti ki
 | (`pageNumber` / `totalPages`). A láblécszöveg tenant-adat: `{{ }}`.
 --}}
<!doctype html>
<html lang="{{ $locale }}" data-theme="light">
<head>
    <meta charset="utf-8">
    <style>{!! str_replace('</', '<\/', $css) !!}</style>
</head>
<body class="vm-report-footer">
    <div class="vm-report-footer__row">
        <span class="vm-report-footer__text">{{ $text }}</span>
        <span class="vm-report-footer__page"><span class="pageNumber"></span> / <span class="totalPages"></span></span>
    </div>
</body>
</html>
