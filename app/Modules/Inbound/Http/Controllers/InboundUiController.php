<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Http\Controllers;

use App\Modules\Inbound\Models\InboundMessage;
use App\Modules\Inbound\Models\InboundRoute;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A bejövő route-ok és levelek KÉPERNYŐJE.
 *
 * A JOGOSULTSÁG EXPLICIT: a route-szabály küldési viselkedést változtat (továbbítás idegen
 * címre), a bejövő levelek listája pedig ügyfél-levelezést mutat — a workspace-tagság
 * önmagában egyikhez sem elég.
 */
final class InboundUiController
{
    public function index(): Response
    {
        Gate::authorize('domain.manage');

        return Inertia::render('Inbound/Routes', [
            'routes' => InboundRoute::query()->orderBy('priority')->orderBy('id')->get()
                ->map(fn (InboundRoute $route) => [
                    'id' => $route->uuid,
                    'description' => $route->description,
                    'priority' => $route->priority,
                    'match_type' => $route->match_type,
                    'match_field' => $route->match_field,
                    'expression' => $route->expression,
                    'actions' => $route->actions,
                    'active' => $route->active,
                    'last_matched_at' => $route->last_matched_at?->toIso8601String(),
                ])->values(),
            'messages' => InboundMessage::query()->orderByDesc('id')->limit(25)->get()
                ->map(fn (InboundMessage $message) => [
                    'id' => $message->uuid,
                    'sender' => $message->sender,
                    'recipient' => $message->recipient,
                    'subject' => $message->subject,
                    'status' => $message->status,
                    'received_at' => $message->received_at?->toIso8601String(),
                ])->values(),
        ]);
    }
}
