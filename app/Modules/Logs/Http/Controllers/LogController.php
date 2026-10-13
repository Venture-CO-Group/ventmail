<?php

declare(strict_types=1);

namespace App\Modules\Logs\Http\Controllers;

use App\Modules\Domains\Models\Domain;
use App\Modules\Logs\Services\DomainStatistics;
use App\Modules\Logs\Services\LogExport;
use App\Modules\Logs\Services\MessageLogQuery;
use App\Modules\Logs\Services\MessageTrace;
use App\Modules\Logs\Services\TagStatistics;
use App\Modules\Sending\Models\Message;
use App\Support\QueryTooExpensive;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Napló- és trace-képernyő (22–23., 29. kritérium).
 *
 * Ez az a felület, ahol a „miért nem érkezett meg?" kérdés megválaszolódik — ezért a lista
 * és a trace UGYANAZT az adatot mutatja, mint az API: nincs külön „UI-logika", ami
 * eltérhetne a szerződéstől.
 *
 * A JOGOSULTSÁG EXPLICIT. A workspace-tagság önmagában nem elég: a napló a címzettek
 * e-mail-címét, a tárgyat és a fogadó szerver válaszát mutatja, az `AbilityMap` pedig
 * fehérlistás — az `author` szerep szándékosan NEM kapja meg a `logs.view`-t. A route-stack
 * (`web + auth + workspace`) csak azt dönti el, MELYIK tenant adata látszik, azt nem, hogy
 * a belépőnek szabad-e látnia.
 */
final class LogController
{
    public function index(Request $request, MessageLogQuery $query): Response
    {
        Gate::authorize('logs.view');

        $filters = self::filters($request);

        $messages = $query->paginate($filters, 50, $request->string('cursor')->toString() ?: null);

        return Inertia::render('Logs/Index', [
            'filters' => $filters,
            'domains' => Domain::query()->orderBy('name')->limit(500)->pluck('name')->values()->all(),
            'statuses' => ['queued', 'accepted', 'delivered', 'deferred', 'bounced', 'rejected'],
            'retentionDays' => (int) config('sending.delivery_attempt_retention_days', 90),
            'exportMaxRows' => LogExport::maxRows(),
            'messages' => [
                'items' => collect($messages->items())->map(fn (Message $message) => [
                    'uuid' => $message->uuid,
                    'status' => $message->status,
                    'reason' => $message->reason,
                    'channel' => $message->channel,
                    'from' => $message->from_email,
                    'recipients' => $message->recipients,
                    'subject' => $message->subject,
                    'tags' => $message->tags,
                    'created_at' => $message->created_at?->toIso8601String(),
                ])->values(),
                'next_cursor' => $messages->nextCursor()?->encode(),
            ],
        ]);
    }

    /**
     * CSV-EXPORT (v1.1 · 7. modul, D173) — streamelt, korlátos, retenció-tudatos,
     * PII-tudatos (alapból maszkolt címzett, tárgy nélkül), auditált és fékezett.
     */
    public function export(Request $request, LogExport $export): StreamedResponse|JsonResponse
    {
        Gate::authorize('logs.view');

        $pii = $request->boolean('pii');

        // A TELJES (nem maszkolt) alak külön képesség (D189): a maszkolt export a naplót
        // olvasóké, a címzett-címek tömeges kiadása az üzemeltető szerepeké.
        if ($pii) {
            Gate::authorize('logs.export_pii');
        }

        $key = 'logs-export:'.$request->user()?->getAuthIdentifier();

        if (RateLimiter::tooManyAttempts($key, (int) config('logs.export_per_minute', 5))) {
            return response()->json(['message' => __('ventmail.plat.exportThrottled')], 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, 60);

        $filters = self::filters($request);

        $export->audit($filters, $pii);

        return response()->streamDownload(function () use ($export, $filters, $pii): void {
            $out = fopen('php://output', 'wb');
            $export->write($filters, $pii, $out);
            fclose($out);
        }, 'ventmail-log-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * A szűrők — az idő-paramétereket ÉRVÉNYES dátummá alakítva (egy elgépelt `since` ne
     * adatbázis-hibát adjon, hanem ne szűrjön).
     *
     * @return array<string, string>
     */
    private static function filters(Request $request): array
    {
        $filters = array_map(
            static fn ($value) => is_string($value) ? mb_substr(trim($value), 0, 320) : '',
            $request->only(['recipient', 'tag', 'channel', 'status', 'domain', 'since', 'until', 'message_id']),
        );

        foreach (['since', 'until'] as $key) {
            if (($filters[$key] ?? '') === '') {
                continue;
            }

            try {
                $date = Carbon::parse($filters[$key]);
                // Csak dátum az „eddig"-ben: az a NAP VÉGÉIG értendő (különben a nap kimaradna).
                $filters[$key] = ($key === 'until' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$key]) === 1
                    ? $date->endOfDay()
                    : $date)->toIso8601String();
            } catch (\Throwable) {
                unset($filters[$key]);
            }
        }

        return $filters;
    }

    /**
     * Tag-szintű statisztika (26. kritérium).
     *
     * A FOLYTONOS AGGREGÁTUMBÓL jön, nem a nyers eseményekből: a retenció azokat törli,
     * tehát futásidejű számolással a 90. napon minden korábbi kampány eredménye eltűnne.
     */
    public function statistics(Request $request, TagStatistics $statistics): Response
    {
        Gate::authorize('analytics.view');

        $days = min(max((int) $request->integer('days', 30), 1), 365);

        return Inertia::render('Logs/Statistics', [
            'days' => $days,
            'channel' => $request->string('channel')->toString() ?: null,
            'rows' => $statistics->forRange(
                now()->subDays($days)->startOfDay(),
                now()->endOfDay(),
                $request->string('channel')->toString() ?: null,
            ),
        ]);
    }

    /**
     * KÜLDÉSI STATISZTIKA domainenként és időben (v1.1 · 7. modul, D175) — a
     * `domain_daily_stats` számlálóiból, korlátos időablakkal és lekérdezési kerettel.
     */
    public function domainStatistics(Request $request, DomainStatistics $statistics): Response
    {
        Gate::authorize('analytics.view');

        $days = in_array((int) $request->integer('days', 30), [7, 30, 90, 365], true) ? (int) $request->integer('days', 30) : 30;

        $domains = Domain::query()->orderBy('name')->limit(500)->get(['id', 'uuid', 'name']);
        $selected = $request->filled('domain') ? $domains->firstWhere('uuid', $request->string('domain')->toString()) : null;

        try {
            $report = $statistics->report(now()->subDays($days - 1)->startOfDay(), now()->endOfDay(), $selected?->getKey());
        } catch (QueryTooExpensive) {
            $report = null;
        }

        return Inertia::render('Logs/DomainStats', [
            'days' => $days,
            'domain' => $selected?->uuid,
            'domains' => $domains->map(fn (Domain $domain) => ['uuid' => (string) $domain->uuid, 'name' => (string) $domain->name])->values()->all(),
            'report' => $report,
        ]);
    }

    public function show(string $uuid, MessageTrace $trace): Response
    {
        Gate::authorize('logs.view');

        $data = $trace->for($uuid);

        if ($data === null) {
            throw new NotFoundHttpException('Ismeretlen üzenet.');
        }

        return Inertia::render('Logs/Trace', ['trace' => $data]);
    }
}
