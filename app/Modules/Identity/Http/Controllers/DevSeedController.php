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

        // Idempotens: a teszt többször is meghívhatja, és nem hízik tőle a napló (egyetlen demó
        // üzenet). A statisztika viszont 30 napos ablakot néz, a napló-események pedig
        // módosíthatatlanok — ezért a demó üzenethez NAPONTA új nyitás/kattintás esemény jön
        // (lent), így a tag-statisztika nem avul el néhány hét után.
        $existing = Message::query()->where('subject', 'Demó számla')->first();

        if ($existing !== null) {
            $this->seedFreshEvents($existing->uuid);

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
        $this->seedFreshEvents($message->uuid);

        return redirect('/logs');
    }

    /** Nyitás és kattintás az elmúlt 24 órában (ha még nincs) — a trace lánca ezekkel ér véget. */
    private function seedFreshEvents(string $messageUuid): void
    {
        foreach (['opened', 'clicked'] as $type) {
            $recent = MessageEvent::query()
                ->where('message_uuid', $messageUuid)
                ->where('type', $type)
                ->where('occurred_at', '>=', now()->subDay())
                ->exists();

            if (! $recent) {
                MessageEvent::create([
                    'message_uuid' => $messageUuid,
                    'type' => $type,
                    'recipient' => 'demo@example.test',
                    'channel' => 'transactional',
                    'tags' => ['demo'],
                ]);
            }
        }
    }
}
