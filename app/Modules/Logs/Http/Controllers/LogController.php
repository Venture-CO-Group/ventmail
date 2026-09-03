<?php

declare(strict_types=1);

namespace App\Modules\Logs\Http\Controllers;

use App\Modules\Logs\Services\MessageLogQuery;
use App\Modules\Logs\Services\MessageTrace;
use App\Modules\Sending\Models\Message;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Napló- és trace-képernyő (22–23., 29. kritérium).
 *
 * Ez az a felület, ahol a „miért nem érkezett meg?" kérdés megválaszolódik — ezért a lista
 * és a trace UGYANAZT az adatot mutatja, mint az API: nincs külön „UI-logika", ami
 * eltérhetne a szerződéstől.
 */
final class LogController
{
    public function index(Request $request, MessageLogQuery $query): Response
    {
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

    public function show(string $uuid, MessageTrace $trace): Response
    {
        $data = $trace->for($uuid);

        if ($data === null) {
            throw new NotFoundHttpException('Ismeretlen üzenet.');
        }

        return Inertia::render('Logs/Trace', ['trace' => $data]);
    }
}
