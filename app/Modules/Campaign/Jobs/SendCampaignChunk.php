<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Jobs;

use App\Modules\Campaign\Events\CampaignProgressed;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignRecipient;
use App\Modules\Campaign\Services\CampaignLifecycle;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * EGY KÖTEG kampány-levél kiküldése (65., 66. kritérium).
 *
 * ## A KAMPÁNY UGYANAZON A MOTORON MEGY
 *
 * A `SendMessage` action hívása nem kényelmi döntés, hanem a `CLAUDE.md` első szabálya: „a
 * kampány ugyanazon a küldőmotoron megy, mint az API-hívás". Ez azt jelenti, hogy a kampány
 * levele MIND A TÍZ PreSendGate-kapun átmegy — domain-hitelesítés, suppression, consent,
 * kvóta, rate-limit, címellenőrzés, leiratkozó fejléc. Egy külön „kampány-küldő" út
 * megkerülné őket, és pontosan ez a golden rule 2 tiltása.
 *
 * ## A CSATORNA `campaign`, nem `marketing`
 *
 * Így a sor-prioritás (`SendQueue::forChannel`) a legalacsonyabbra teszi, és a
 * `Channel::queuePriority()` szerint a tranzakciós MEGELŐZI. A consent-követelmény ettől
 * nem lazul: a `Channel::requiresConsent()` a `campaign`-re is igaz.
 *
 * ## A KÖTEG NEM SZAKÍTJA MEG A TÖBBIT
 *
 * Egy címzett hibája (elutasított cím, kapu-blokk) a SORÁT jelöli meg, nem dobja el a
 * köteget. Egy `Throwable` a jobból a batch `allowFailures()` mellett is azt jelentené,
 * hogy 250 címzett közül egy hibás miatt 249 nem kap levelet.
 */
final class SendCampaignChunk implements ShouldQueue
{
    use Batchable, Queueable;

    /** @param list<int> $recipientIds */
    public function __construct(
        private readonly string $campaignUuid,
        private readonly int $workspaceId,
        private readonly array $recipientIds,
    ) {
        $this->afterCommit();
    }

    public function handle(WorkspaceContext $context, SendMessage $send): void
    {
        $workspace = Workspace::query()->whereKey($this->workspaceId)->first();

        if ($workspace === null) {
            return;
        }

        $previous = $context->hasWorkspace() ? $context->workspace() : null;

        $context->set($workspace);

        try {
            $campaign = Campaign::query()->with('template')->where('uuid', $this->campaignUuid)->first();

            if ($campaign === null) {
                return;
            }

            // A SZÜNETELTETÉS ÉS A VISSZAVONÁS ITT is számít: a batch törlése nem
            // azonnali, tehát egy már futó job még beeshet. A `pending` sorok érintetlenül
            // maradnak, tehát a folytatás pontosan onnan veszi fel.
            if (! in_array((string) $campaign->status, ['sending'], true)) {
                return;
            }

            if ($this->batch()?->cancelled() === true) {
                return;
            }

            foreach ($this->recipients($campaign) as $recipient) {
                $this->sendTo($campaign, $recipient, $send);
            }

            $this->reportProgress($campaign);
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }
    }

    /** @return Collection<int, CampaignRecipient> */
    private function recipients(Campaign $campaign): Collection
    {
        return $campaign->recipients()
            // AZ EAGER LOAD nem teljesítmény-finomítás: a projekt tiltja a lazy loadingot
            // (`preventLazyLoading`), tehát a `$recipient->variant` KIVÉTELT dob. És jó
            // okkal: 250 címzettnél a soronkénti variáns- és sablon-lekérdezés 500 extra
            // körutazás lenne EGY kötegben.
            ->with(['variant.template'])
            ->whereIn('id', $this->recipientIds)
            // CSAK a `pending` sorok: egy újrapróbált job nem küld másodszor. Ez az
            // idempotencia gyakorlati alapja — a `sent` sort nem nyúlja meg.
            ->where('status', 'pending')
            ->orderBy('id')
            ->get();
    }

    private function sendTo(Campaign $campaign, CampaignRecipient $recipient, SendMessage $send): void
    {
        $variant = $recipient->variant;
        $template = $variant?->template ?? $campaign->template;

        if ($template === null) {
            $recipient->forceFill(['status' => 'failed', 'skip_reason' => 'no_template'])->save();

            return;
        }

        try {
            $result = $send([
                // A `campaign` csatorna: legalacsonyabb sor-prioritás, de a
                // consent-követelmény ugyanaz (`Channel::requiresConsent()`).
                'channel' => 'campaign',
                'from_email' => (string) $campaign->from_email,
                'from_name' => $campaign->from_name,
                'to' => [(string) $recipient->email],
                'subject' => (string) ($variant?->subject ?? $campaign->subject),
                'html' => (string) $template->html,
                'text' => (string) $template->text,
                // A KAMPÁNY-CÍMKE: a tag-szintű statisztika (A3) és a marketing analitika
                // (B4) ebből dolgozik — ugyanabból az esemény-forrásból, mint a
                // platform-napló.
                'tags' => array_values(array_filter([
                    'campaign:'.$campaign->uuid,
                    $variant === null ? null : 'variant:'.$variant->label,
                ])),
                // AZ IDEMPOTENCIA-KULCS a kampány és a címzett párosa: egy újrapróbált
                // köteg ugyanazt a kulcsot adja, tehát a levél nem megy ki kétszer — a
                // védelem így nem csak a `pending` szűrőn múlik.
                'idempotency_key' => 'campaign:'.$campaign->uuid.':'.$recipient->email_hash,
            ]);

            $allowed = $result->decision->allowed;

            $recipient->forceFill([
                'status' => $allowed ? 'sent' : 'skipped',
                // A KAPU DÖNTÉSE a `skip_reason`: a riportnak meg kell tudnia mondani, hogy
                // MIÉRT nem kapta meg valaki — „nincs consent" és „suppression-listán van"
                // két különböző beszélgetés az ügyféllel.
                'skip_reason' => $allowed ? null : (string) $result->decision->reason,
                'message_uuid' => $result->message?->uuid,
                'sent_at' => $allowed ? now() : null,
            ])->save();

            $campaign->increment($allowed ? 'sent_count' : 'failed_count');

            if ($allowed && $variant !== null) {
                $variant->increment('sent_count');
            }
        } catch (Throwable $e) {
            // EGY CÍMZETT HIBÁJA a SORÁT jelöli meg, nem dobja el a köteget: 250 közül egy
            // hibás miatt 249 nem maradhat levél nélkül.
            report($e);

            $recipient->forceFill([
                'status' => 'failed',
                'skip_reason' => 'error:'.class_basename($e),
            ])->save();

            $campaign->increment('failed_count');
        }
    }

    /**
     * A PROGRESSZ (65. kritérium) — Reverb-en a felületre.
     *
     * Kötegenként EGYSZER, nem címzettenként: százezer címzettnél a soronkénti esemény a
     * WebSocket-csatornát tömné meg, és a felület egy másodpercen belül több száz
     * frissítést kapna — használhatatlanul.
     */
    private function reportProgress(Campaign $campaign): void
    {
        $campaign = $campaign->fresh();

        if ($campaign === null) {
            return;
        }

        $pending = $campaign->recipients()->where('status', 'pending')->count();

        CampaignProgressed::dispatch(
            (string) $campaign->uuid,
            (int) $campaign->workspace_id,
            (int) $campaign->recipient_count,
            (int) $campaign->sent_count,
            (int) $campaign->failed_count,
        );

        // AZ UTOLSÓ KÖTEG zárja a kampányt. A batch `finally` callbackje elegánsabb lenne,
        // de az a batch elkészítésekor rögzül — egy folytatás (`resume`) új batchet indít,
        // és a régi callback már nem hívódna meg.
        if ($pending === 0 && (string) $campaign->status === 'sending') {
            app(CampaignLifecycle::class)->complete($campaign);
        }
    }
}
