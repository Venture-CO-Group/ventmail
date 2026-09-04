<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Jobs;

use App\Modules\Domains\Models\Domain;
use App\Modules\Inbound\Contracts\InboundRecipientResolver;
use App\Modules\Inbound\Models\InboundMessage;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `forward(email)` — továbbítás a KIMENŐ motoron (32. kritérium).
 *
 * A továbbítás KIMENŐ KÜLDÉS, tehát ugyanaz a kapu érvényes rá, mint bármi másra: a
 * PreSendGate hat kapuja (golden rule 2). Nincs „belső" út a gate mellett — ha lenne, a
 * suppression-listás címre egy route-szabállyal bárki küldhetne.
 *
 * A FELADÓ MI VAGYUNK, nem az eredeti küldő. Ha az eredeti feladót tartanánk meg, a MI
 * IP-nkről menne ki egy idegen domain nevében írt levél: az SPF azonnal megbukna, és a
 * fogadó minket büntetne érte. Az eredeti feladó a `Reply-To`-ba kerül — így a válasz oda
 * megy, ahova az olvasó szánja.
 *
 * HÁROM HUROK-ZÁR. Enélkül egy `catch_all → forward("archiv@sajat-domain.hu")` szabály
 * VÉGTELEN, erősítő ciklust indít: a továbbított levél a saját MX-ünkre érkezik vissza, új
 * bejövő üzenet lesz, újra illeszkedik, újra továbbít. Minden kör egy tárolt objektum, egy
 * DB-sor, egy számlázott küldés és egy DKIM-aláírt levél a saját IP-nkről — mérve négy
 * körben, és az idempotencia-kulcs (ami a bejövő uuid-ból épül) körönként MÁS, tehát nem
 * fogja meg.
 *
 *  1. HOP-SZÁMLÁLÓ: a `Received:` fejlécek száma (RFC 5321 §6.3 ajánlása szerint 100 a
 *     plafon; mi szigorúbbak vagyunk) — ez a szabvány, minden MTA erre épít;
 *  2. SAJÁT NYOM: a `X-VentMail-Hops` fejlécet mi írjuk, és mi is OLVASSUK;
 *  3. ÖNMAGUNKRA MUTATÓ CÉL: ha a továbbítás célja a SAJÁT verifikált inbound-domainünk, a
 *     szabály önmagát hívná — ezt eleve nem indítjuk el.
 *
 * A NULL-SENDER (bounce) sosem továbbítódik: a bounce-ok továbbítása a klasszikus
 * levél-vihar receptje.
 */
final class ForwardInboundToEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        private readonly int $workspaceId,
        private readonly string $messageUuid,
        private readonly string $target,
    ) {
        $this->afterCommit();
    }

    /** Ennyi továbbítási körnél nem megyünk tovább. */
    private const MAX_HOPS = 8;

    public function handle(WorkspaceContext $context, SendMessage $send, InboundRecipientResolver $resolver): void
    {
        $workspace = Workspace::query()->whereKey($this->workspaceId)->first();

        if ($workspace === null) {
            return;
        }

        $previous = $context->hasWorkspace() ? $context->workspace() : null;

        $context->set($workspace);

        try {
            $message = InboundMessage::query()->where('uuid', $this->messageUuid)->first();

            if ($message === null) {
                return;
            }

            // A PARSE-OLATLAN levelet nem továbbítjuk: üres törzzsel kimenő levél az
            // ügyfélnek se használ, a reputációnknak meg árt.
            if (! in_array((string) $message->status, ['parsed', 'routed'], true)) {
                return;
            }

            // A BOUNCE-t nem továbbítjuk. A null-sender kézbesítési értesítés; továbbítva a
            // válasz-lánc a mi címünkre menne, és a levél-vihar klasszikus receptje.
            if (($message->sender ?? '') === '') {
                return;
            }

            // ÖNMAGUNKRA MUTATÓ CÉL: a szabály önmagát hívná, körönként új üzenettel.
            if ($resolver->resolve($this->target) !== null) {
                Log::warning('A továbbítás célja a saját inbound-domainünk — hurok-védelem.', [
                    'inbound_message' => $this->messageUuid,
                ]);

                return;
            }

            $hops = $this->hops($message);

            if ($hops >= self::MAX_HOPS) {
                Log::warning('A továbbítási lánc elérte a plafont — hurok-védelem.', [
                    'inbound_message' => $this->messageUuid,
                    'hops' => $hops,
                ]);

                return;
            }

            $domain = Domain::query()->whereKey($message->domain_id)->first();

            if ($domain === null) {
                Log::warning('A továbbításhoz nincs feladó-domain.', ['inbound_message' => $this->messageUuid]);

                return;
            }

            $send([
                // TRANZAKCIÓS: a továbbítás nem marketing, és nem is tiltható le
                // marketing-leiratkozással (golden rule 4).
                'channel' => 'transactional',
                // A MI domainünk. A `noreply@` prefix szándékos: a válasz a `Reply-To`-ra
                // menjen, ne egy nem létező postafiókba.
                'from_email' => 'noreply@'.$domain->name,
                'from_name' => 'ventMail továbbítás',
                'to' => [$this->target],
                'subject' => (string) ($message->subject ?? '(tárgy nélkül)'),
                'html' => $message->body_html,
                'text' => $message->body_plain,
                'headers' => array_filter([
                    // Az EREDETI feladó: ide megy a válasz. CSAK ha érvényes, egyetlen cím —
                    // a boríték-feladó jöhetett csonkán vagy többesben, és a saját
                    // DKIM-aláírásunkkal kimenő, hamis válasz-című levél adathalász-erősítés.
                    'Reply-To' => filter_var((string) $message->sender, FILTER_VALIDATE_EMAIL) === false
                        ? null
                        : $message->sender,
                    // A továbbítás TÉNYE látszik a fejlécben — a fogadó lássa, honnan jött.
                    'X-VentMail-Forwarded-For' => $message->recipient,
                    'X-VentMail-Inbound-Id' => $message->uuid,
                    // A HOP-SZÁMLÁLÓ, amit a következő körben MI OLVASUNK.
                    'X-VentMail-Hops' => (string) ($hops + 1),
                ]),
                'tags' => ['inbound-forward'],
                // Az idempotencia-kulcs a BEJÖVŐ üzenet azonosítójából: ha a job kétszer fut
                // (worker-újraindulás), a levél akkor sem megy ki kétszer.
                'idempotency_key' => 'inbound-fwd-'.$message->uuid.'-'.substr(hash('sha256', $this->target), 0, 12),
            ]);
        } catch (Throwable $e) {
            // A gate elutasítása NEM hiba: az üzenet trace-e megmutatja, melyik kapu állt
            // meg. A kivételt viszont nem nyeljük el csendben.
            Log::warning('A bejövő levél továbbítása nem sikerült.', [
                'inbound_message' => $this->messageUuid,
                'error' => $e::class,
            ]);
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }
    }

    /**
     * Hányadik körben járunk?
     *
     * A saját `X-VentMail-Hops` fejlécünk a pontos szám; a `Received:` sorok darabszáma a
     * tartalék, ami akkor is fog, ha a lánc egy idegen MTA-n keresztül fordul vissza.
     */
    private function hops(InboundMessage $message): int
    {
        $headers = (array) $message->headers;

        $own = (int) ($headers['x-ventmail-hops'] ?? 0);

        // A `headers()` az ELSŐ `Received:`-et tartja meg, tehát a darabszámot a nyers
        // fejléc-értékből nem tudjuk; a saját számlálónk viszont pontos. Ha az idegen
        // láncban elveszne, a jelenléte akkor is jelez.
        $forwarded = isset($headers['x-ventmail-forwarded-for']) ? 1 : 0;

        return max($own, $forwarded);
    }
}
