<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Domains\Models\Domain;
use App\Modules\Events\Models\MessageEvent;
use App\Modules\Logs\Models\SmtpTransaction;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Sending\Models\Message;
use Illuminate\Http\RedirectResponse;

/**
 * KIZÁRÓLAG fejlesztői és teszt-környezetben: DEMÓ-ADAT a napló-flow-hoz.
 *
 * Miért kell: a napló- és trace-képernyő böngésző-tesztje eddig ÜRES naplón futott, és ha
 * nem talált sort, kilépett — „zölden". Egy teszt, ami a hiányzó adatra magától átugrik, nem
 * a képernyőt méri, hanem a saját türelmét. A 29. kritérium ennél többet ígér.
 *
 * Ugyanaz a két független kapcsoló védi, mint a dev-belépést: a route regisztrációja
 * környezethez kötött, ÉS a kontroller maga is ellenőriz.
 */
final class DevSeedController
{
    public function __invoke(SendMessage $send): RedirectResponse
    {
        abort_unless(
            config('identity.dev_login_enabled') && ! app()->isProduction() && app()->environment('local', 'testing'),
            404
        );

        Domain::firstOrCreate(
            ['name' => 'pelda.hu'],
            ['status' => 'verified', 'verified_at' => now()],
        );

        // Idempotens: a teszt többször is meghívhatja, és nem hízik tőle a napló.
        if (Message::query()->where('subject', 'Demó számla')->exists()) {
            return redirect('/logs');
        }

        $result = $send([
            'channel' => 'transactional',
            'from_email' => 'no-reply@pelda.hu',
            'subject' => 'Demó számla',
            'to' => ['demo@example.test'],
            'html' => '<p>Ez egy demó levél a napló-flow teszteléséhez.</p>',
            'tags' => ['demo'],
            'idempotency_key' => 'dev-seed-1',
        ]);

        $message = $result->message;

        // A trace-hez SMTP-párbeszéd is kell: enélkül a képernyő fele üres maradna, és a
        // teszt nem tudná megmondani, hogy megjelenik-e.
        SmtpTransaction::firstOrCreate(
            ['message_uuid' => $message->uuid, 'recipient' => 'demo@example.test'],
            [
                'remote_host' => 'mx1.example.test',
                'response_code' => 250,
                'response_text' => 'Ok: queued',
                'lines' => [
                    ['direction' => 'out', 'line' => 'MAIL FROM:<no-reply@pelda.hu>'],
                    ['direction' => 'in', 'line' => '250 2.1.0 Ok'],
                ],
                'started_at' => now(),
            ],
        );

        // Nyitás és kattintás: a trace lánca ezekkel ér véget (23. kritérium).
        // A folytonos aggregátumot NEM frissítjük kézzel: a nézet `materialized_only = false`,
        // tehát a friss (még nem anyagosított) ablakot valós időben egészíti ki — a
        // statisztika-képernyő így azonnal látja ezt a levelet.
        foreach (['opened', 'clicked'] as $type) {
            if (! MessageEvent::query()->where('message_uuid', $message->uuid)->where('type', $type)->exists()) {
                MessageEvent::create([
                    'message_uuid' => $message->uuid,
                    'type' => $type,
                    'recipient' => 'demo@example.test',
                    'channel' => 'transactional',
                    'tags' => ['demo'],
                ]);
            }
        }

        return redirect('/logs');
    }
}
