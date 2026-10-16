/*
 | A PDF-RIPORT grafikonjai (v1.1 · 8. modul, D239) — a Gotenberg Chromiumjában, HÁLÓZAT NÉLKÜL.
 |
 | Vue nélkül, csak `echarts/core` + vonal/oszlop/kör + SVG-renderer (éles vektor a PDF-ben),
 | animáció nélkül. Az adat a szerver által írt `<script type="application/json" id="vm-report-data">`
 | blokkból jön (JSON_HEX_* kódolással). A színek a `--vt-*` tokenek számított értékei (a
 | report.css a `@venture-co-group/design` tokenjeit hozza). Kirajzolás után egy képkocka múlva
 | `window.__vmReportReady = true` — erre vár a Gotenberg (`waitForExpression`); egy JS-hiba a
 | `failOnConsoleExceptions` miatt a renderelést bukja, nem ad grafikon nélküli PDF-et.
 */
import { BarChart, LineChart, PieChart } from 'echarts/charts';
import { GridComponent, LegendComponent, MarkLineComponent } from 'echarts/components';
import { init, use } from 'echarts/core';
import { SVGRenderer } from 'echarts/renderers';
import './report.css';

use([LineChart, BarChart, PieChart, GridComponent, LegendComponent, MarkLineComponent, SVGRenderer]);

interface Serie {
    name: string;
    data: number[];
    token: string;
    stack?: string;
    line?: boolean;
    threshold?: boolean;
}

interface ChartDef {
    title: string;
    type: 'line' | 'bar' | 'pie';
    labels?: string[];
    series?: Serie[];
    items?: Array<{ name: string; value: number }>;
    percent?: boolean;
    threshold?: { value: number; name: string };
}

declare global {
    interface Window {
        __vmReportReady?: boolean;
    }
}

const PALETTE = [
    '--vt-print-series-1',
    '--vt-print-series-2',
    '--vt-print-series-3',
    '--vt-status-success',
    '--vt-status-warning',
    '--vt-status-danger',
    '--vt-print-series-4',
    '--vt-print-text-muted',
];

const style = getComputedStyle(document.documentElement);
const token = (name: string): string => style.getPropertyValue(name).trim();

function option(def: ChartDef): Record<string, unknown> {
    const text = token('--vt-print-text-secondary');
    const grid = token('--vt-print-rule');
    const base = { animation: false, textStyle: { color: text, fontFamily: token('--vt-font-body') || undefined } };

    if (def.type === 'pie') {
        return {
            ...base,
            legend: { orient: 'vertical', right: 0, top: 'middle', textStyle: { color: text } },
            series: [
                {
                    type: 'pie',
                    radius: ['50%', '78%'],
                    center: ['30%', '50%'],
                    label: { show: false },
                    data: (def.items ?? []).map((item, index) => ({ ...item, itemStyle: { color: token(PALETTE[index % PALETTE.length]) } })),
                },
            ],
        };
    }

    return {
        ...base,
        legend: { top: 0, textStyle: { color: text } },
        grid: { left: 8, right: 32, top: 32, bottom: 8, containLabel: true },
        xAxis: { type: 'category', boundaryGap: def.type === 'bar', data: def.labels ?? [], axisLine: { lineStyle: { color: grid } }, axisLabel: { color: text, hideOverlap: true } },
        yAxis: { type: 'value', splitLine: { lineStyle: { color: grid } }, axisLabel: { color: text, formatter: def.percent ? '{value}%' : '{value}' } },
        series: (def.series ?? []).map((serie) => {
            const color = token(serie.token);
            const asLine = def.type === 'line' || serie.line === true;

            return {
                name: serie.name,
                type: asLine ? 'line' : 'bar',
                stack: asLine ? undefined : serie.stack,
                showSymbol: false,
                smooth: asLine,
                barMaxWidth: 18,
                data: serie.data,
                itemStyle: { color },
                lineStyle: asLine ? { color } : undefined,
                markLine:
                    serie.threshold && def.threshold
                        ? { symbol: 'none', silent: true, label: { formatter: def.threshold.name, color: text, position: 'insideEndTop' }, lineStyle: { color: token('--vt-status-danger'), type: 'dashed' }, data: [{ yAxis: def.threshold.value }] }
                        : undefined,
            };
        }),
    };
}

const payload = JSON.parse(document.getElementById('vm-report-data')?.textContent ?? '{}') as { charts?: Record<string, ChartDef> };

document.querySelectorAll<HTMLElement>('[data-chart]').forEach((element) => {
    const def = payload.charts?.[element.dataset.chart ?? ''];

    if (!def) return;

    const chart = init(element, null, { renderer: 'svg' });
    chart.setOption(option(def));
});

requestAnimationFrame(() => {
    window.__vmReportReady = true;
});
