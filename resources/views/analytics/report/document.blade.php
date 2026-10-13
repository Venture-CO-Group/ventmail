{{--
 | v1.1 · 8. modul — BRANDELT PDF-RIPORT (A4 álló), a Gotenberg Chromiumja rendereli.
 |
 | HÁLÓZAT NÉLKÜL fut (D239): a CSS, a JS és a logó ugyanabban a multipart-kérésben érkezik
 | fájlként, relatív hivatkozással; a meta-CSP csak `file:`-t enged. Minden tenant-szöveg `{{ }}`-n
 | át, escape-elve; tenant-HTML nincs. Színek csak `--vt-*` tokenből (report.css), a brand-szín a
 | szerveren validált `#RRGGBB` (`--vm-brand`).
 --}}
<!doctype html>
<html lang="{{ $locale }}" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Security-Policy" content="default-src 'none'; script-src file:; style-src file: 'unsafe-inline'; img-src file: data:; font-src file:">
    <title>{{ $title }} · {{ $subtitle }}</title>
    <link rel="stylesheet" href="report.css">
</head>
<body class="vm-report vm-report--cover-{{ $brand['coverText'] }}" @if ($brand['color']) style="--vm-brand: {{ $brand['color'] }}" @endif>
    <section class="vm-report__cover" data-testid="report-cover">
        <div class="vm-report__band">
            <div class="vm-report__brand">
                <img class="vm-report__logo" src="{{ $logoFile }}" alt="">
                <span class="vm-report__company">{{ $companyName }}</span>
            </div>
            <h1 class="vm-report__title">{{ $title }}<br><span>{{ $subtitle }}</span></h1>
            <p class="vm-report__range">{{ $dateRange }}</p>
        </div>
        <p class="vm-report__generated">{{ $generatedAt }}</p>
    </section>

    @if ($kpis !== [])
        <section class="vm-report__section">
            <h2>{{ $t->get('analytics.report.kpis') }}</h2>
            <div class="vm-report__kpis">
                @foreach ($kpis as [$label, $value])
                    <div class="vm-report__kpi"><b>{{ $value }}</b><span>{{ $label }}</span></div>
                @endforeach
            </div>
        </section>
    @endif

    @foreach ($notes as $note)
        <p class="vm-report__note">{{ $note }}</p>
    @endforeach

    @foreach ($charts as $key => $chart)
        <section class="vm-report__section vm-report__keep">
            <h2>{{ $chart['title'] }}</h2>
            @isset($chart['note'])<p class="vm-report__caption">{{ $chart['note'] }}</p>@endisset
            <div class="vm-report__chart vm-report__chart--{{ $chart['type'] }}" data-chart="{{ $key }}"></div>
        </section>
    @endforeach

    @foreach ($tables as $table)
        <section class="vm-report__section">
            <h2>{{ $table['title'] }}</h2>
            @isset($table['caption'])<p class="vm-report__caption">{{ $table['caption'] }}</p>@endisset
            @if ($table['rows'] === [])
                <p class="vm-report__note">{{ $table['empty'] ?? $t->get('analytics.noData') }}</p>
            @else
                <table class="vm-report__table">
                    <thead><tr>@foreach ($table['head'] as $head)<th>{{ $head }}</th>@endforeach</tr></thead>
                    <tbody>
                        @foreach ($table['rows'] as $row)
                            <tr>@foreach ($row as $index => $cell)<td @class(['vm-report__mono' => ($table['mono'] ?? null) === $index])>{{ $cell }}</td>@endforeach</tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    @endforeach

    <script type="application/json" id="vm-report-data">{!! $chartJson !!}</script>
    <script src="report.js"></script>
</body>
</html>
