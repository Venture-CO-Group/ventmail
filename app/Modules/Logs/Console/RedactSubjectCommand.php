<?php

declare(strict_types=1);

namespace App\Modules\Logs\Console;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Events\Models\MessageEvent;
use App\Modules\Logs\Models\ErasedRecipient;
use App\Modules\Logs\Models\SmtpTransaction;
use App\Modules\Sending\Models\DeliveryAttempt;
use App\Modules\Sending\Models\Message;
use App\Modules\Sending\Models\RawMimeMessage;
use App\Modules\Sending\Services\DomainNormalizer;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Modules\Webhooks\Models\WebhookDelivery;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ÉRINTETTI TÖRLÉSI KÉRÉS (GDPR Art. 17) — egy címhez tartozó személyes adat eltávolítása.
 *
 * A retenció IDŐ alapján takarít; ez SZEMÉLY alapján. A kettő nem helyettesíti egymást: egy
 * törlési kérésre nem válasz az, hogy „kilencven nap múlva magától eltűnik".
 *
 * A TÉNY megmarad, a SZEMÉLY tűnik el: az esemény típusa, ideje és a workspace ott marad,
 * tehát a kézbesítési statisztika és a panasz-bizonyíték nem sérül. Ez nem kibúvó: az
 * anonimizált aggregátum már nem személyes adat, és a törlési jog nem terjed ki rá.
 *
 * HAT tárolót érint, és ez a lista nem esetleges:
 *  - `messages` — a napló FŐtáblája (címzett-lista, tárgy, kirenderelt törzs). A melléktáblák
 *    kiürítése önmagában nem teljesítés: a napló-képernyő a főtáblából olvas;
 *  - `raw_mime_messages` — a beküldött nyers levél, benne a teljes `To:` fejléc és a törzs;
 *  - `message_events` — adatbázis-trigger őrzi, hogy CSAK redakció mehet át;
 *  - `smtp_transactions`, `delivery_attempts`, `webhook_deliveries`.
 *
 * És egy HETEDIK lépés, ami nélkül a törlés visszafordítható: a cím hash-e bekerül az
 * `erased_recipients` táblába, és onnantól a nyomkövetés SEM ír rá új eseményt.
 */
final class RedactSubjectCommand extends Command
{
    protected $signature = 'logs:redact-subject
        {email : Az érintett e-mail-címe}
        {--workspace= : Csak ehhez a workspace-hez (uuid vagy slug); alapból MINDEGYIK}
        {--dry-run : Csak megszámolja, mit érintene — nem ír}';

    protected $description = 'Egy címhez tartozó személyes adat eltávolítása a naplóból (GDPR Art. 17).';

    public function handle(WorkspaceContext $context, DomainNormalizer $normalizer, AuditLogger $audit): int
    {
        // A TÁROLT alakra kell keresni: a helyi rész változatlan, a domain punycode. Naiv
        // `strtolower()`-rel az ékezetes domainű címzett kérése csendben nulla sort érintene
        // — és azt jelentenénk vissza, hogy „nincs mit törölni".
        $email = $normalizer->email(trim((string) $this->argument('email')));

        if ($email === '' || ! str_contains($email, '@')) {
            $this->error('Érvénytelen e-mail-cím.');

            return self::FAILURE;
        }

        $redacted = ['messages' => 0, 'mime' => 0, 'events' => 0, 'smtp' => 0, 'attempts' => 0, 'webhooks' => 0];

        // SZÁRAZ FUTÁS: egy visszavonhatatlan, tenanteken átnyúló művelet előtt látni kell,
        // mit érintene. A tranzakció a végén visszagördül — a számok viszont valódiak.
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            DB::beginTransaction();
        }

        try {
            $this->workspaces()->chunkById(100, function ($workspaces) use ($context, $email, &$redacted): void {
                foreach ($workspaces as $workspace) {
                    $context->set($workspace);

                    $redacted['messages'] += $this->redactMessages($email, $redacted);
                    $redacted['events'] += $this->redactEvents($email);

                    $redacted['smtp'] += SmtpTransaction::query()
                        ->where('recipient', $email)
                        ->update(['recipient' => null, 'response_text' => null, 'lines' => [], 'redacted_at' => now()]);

                    $redacted['attempts'] += DeliveryAttempt::query()
                        ->where('recipient', $email)
                        ->update(['recipient' => null, 'response' => null, 'redacted_at' => now()]);

                    $redacted['webhooks'] += $this->redactWebhookDeliveries($email);

                    // A TARTÓS TILTÁS. Enélkül a kiküldött levélben lévő pixel egyetlen
                    // betöltéssel visszaírná a címet — és a webhookon harmadik félhez is.
                    ErasedRecipient::query()->firstOrCreate(
                        ['recipient_hash' => ErasedRecipient::hash($email)],
                        ['erased_at' => now()],
                    );
                }
            });
        } finally {
            if ($dryRun) {
                DB::rollBack();
            }

            $context->forget();
        }

        // AUDITÁLT. Ez egy visszavonhatatlan, tenanteken átnyúló admin-művelet (golden rule
        // 11), és épp ez az a művelet, amiről az adatvédelmi hatóságnak számot kell adni:
        // mikor, mire, mekkora hatással futott. A CÍM maga nem kerül az auditba — csak a
        // hash-e —, különben az audit-napló lenne a legérzékenyebb címlistánk.
        if (! $dryRun) {
            $audit->log('logs.redact_subject', context: [
                'recipient_hash' => ErasedRecipient::hash($email),
                'workspace' => (string) ($this->option('workspace') ?: 'mind'),
                'affected' => $redacted,
            ]);
        }

        $this->info(sprintf(
            ($dryRun ? '[SZÁRAZ FUTÁS] ' : '').'Redaktálva — üzenet: %d, nyers MIME: %d, esemény: %d, SMTP-párbeszéd: %d, kísérlet: %d, webhook-sor: %d',
            $redacted['messages'],
            $redacted['mime'],
            $redacted['events'],
            $redacted['smtp'],
            $redacted['attempts'],
            $redacted['webhooks'],
        ));

        return self::SUCCESS;
    }

    /**
     * MELY workspace-ekre fut?
     *
     * Alapból mindegyikre — egy érintett több tenantnál is szerepelhet, és a kérés a
     * platformra szól. A `--workspace` viszont szűkíthet: egy konkrét ügyfél kérésénél
     * nincs okunk a többiek adatához nyúlni.
     *
     * A `withTrashed()` szándékos: az offboardolt tenant adata is adat.
     */
    private function workspaces(): Builder
    {
        $query = Workspace::query()->withTrashed()->orderBy('id');

        if (($only = (string) $this->option('workspace')) !== '') {
            $query->where(fn (Builder $inner) => $inner->where('uuid', $only)->orWhere('slug', $only));
        }

        return $query;
    }

    /**
     * A napló FŐTÁBLÁJA.
     *
     * A `recipients` jsonb-TÖMB: egy batch-sorban ezer cím lehet. Az érintettet KIVESSZÜK a
     * listából, a többiek adatát nem bántjuk — az ő megőrzésükhöz jogalapunk van. A tárgy és
     * a kirenderelt törzs viszont az egész sorra vonatkozik: ha az érintett volt az egyetlen
     * címzett, mindkettő megy.
     *
     * @param  array<string, int>  $redacted
     */
    private function redactMessages(string $email, array &$redacted): int
    {
        $count = 0;

        Message::query()
            ->whereJsonContains('recipients', $email)
            // `eachById`, NEM `each`: az utóbbi OFFSET-tel lapoz, miközben a callback épp azt
            // a feltételt szünteti meg, amire szűrünk — a második lap offsetje már a maradék
            // halmazba mutatna, és sorok maradnának ki. Egy törlési kérésnél ez azt jelentené,
            // hogy „teljesítettük", miközben nem.
            ->eachById(function (Message $message) use ($email, &$count, &$redacted): void {
                $remaining = array_values(array_filter(
                    (array) $message->recipients,
                    fn ($recipient) => (string) $recipient !== $email,
                ));

                $options = (array) $message->options;

                // A törzs a MEGSZEMÉLYESÍTETT tartalom lenyomata is: ha nincs több címzett,
                // nincs kinek megőrizni.
                if ($remaining === []) {
                    unset($options['body_html'], $options['body_text']);
                }

                $message->forceFill([
                    'recipients' => $remaining,
                    'subject' => $remaining === [] ? null : $message->subject,
                    'options' => $options,
                    'redacted_at' => now(),
                ])->save();

                if ($remaining === []) {
                    $redacted['mime'] += RawMimeMessage::query()
                        ->where('message_uuid', $message->uuid)
                        ->delete();
                }

                $count++;
            }, 500);

        return $count;
    }

    private function redactEvents(string $email): int
    {
        // Egyetlen UPDATE, nem soronkénti bejárás: a `message_events` triggere pontosan ezt
        // az alakot engedi át (PII-mezők nullázva + `redacted_at`), és így nincs olyan lap,
        // ami kimaradhat.
        return MessageEvent::query()
            ->where('recipient', $email)
            ->whereNull('redacted_at')
            ->update([
                'recipient' => null,
                'variables' => '{}',
                'payload' => '{}',
                'redacted_at' => now(),
            ]);
    }

    /**
     * A webhook-payload NEM csak a `message.recipient`-ben hordozhatja a címet: benne vannak a
     * `v:*` változók (amiket a hívó adott meg, és amikbe bármit tehetett), a `response_excerpt`
     * pedig a fogadó válaszának eleje, ami gyakran visszaidézi a címzettet.
     */
    private function redactWebhookDeliveries(string $email): int
    {
        return WebhookDelivery::query()
            ->where(fn (Builder $query) => $query
                ->whereJsonContains('payload->message->recipient', $email)
                ->orWhereRaw('payload::text like ?', ['%'.$email.'%'])
                ->orWhere('response_excerpt', 'like', '%'.$email.'%'))
            ->update(['payload' => [], 'response_excerpt' => null, 'redacted_at' => now()]);
    }
}
