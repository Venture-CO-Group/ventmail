<?php

declare(strict_types=1);

namespace App\Modules\Logs\Http\Controllers;

use App\Modules\Logs\Services\MessageLogQuery;
use App\Modules\Logs\Services\MessageTrace;
use App\Modules\Logs\Services\TagStatistics;
use App\Modules\Sending\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
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

        $filters = $request->only(['recipient', 'tag', 'channel', 'status', 'domain', 'since', 'until']);

        $messages = $query->paginate($filters, 50, $request->string('cursor')->toString() ?: null);

        return Inertia::render('Logs/Index', [
            'filters' => $filters,
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
