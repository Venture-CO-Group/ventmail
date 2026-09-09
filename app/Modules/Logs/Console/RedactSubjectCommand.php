<?php

declare(strict_types=1);

namespace App\Modules\Logs\Console;

use App\Modules\Analytics\Services\ClickMap;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Audit\Services\KeyedHash;
use App\Modules\Campaign\Models\CampaignRecipient;
use App\Modules\Events\Models\MessageEvent;
use App\Modules\Inbound\Contracts\RawMailStore;
use App\Modules\Inbound\Models\InboundAttachment;
use App\Modules\Inbound\Models\InboundMessage;
use App\Modules\Journey\Models\JourneyRun;
use App\Modules\Logs\Models\ErasedRecipient;
use App\Modules\Logs\Models\SmtpTransaction;
use App\Modules\Sending\Models\DeliveryAttempt;
use App\Modules\Sending\Models\Message;
use App\Modules\Sending\Models\RawMimeMessage;
use App\Modules\Sending\Services\DomainNormalizer;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Modules\Validation\Models\ValidationResult;
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
        // A TÁROLT alakra kell keresni: a domain punycode. Naiv `strtolower()`-rel az
        // ékezetes domainű címzett kérése csendben nulla sort érintene — és azt jelentenénk
        // vissza, hogy „nincs mit törölni".
        //
        // Az ÖSSZEHASONLÍTÁS viszont KIS/NAGYBETŰ-FÜGGETLEN (`listKey`). A tárolás a helyi
        // részt érintetlenül hagyja (RFC szerint az elvben jelentős), az SMTP-világ viszont
        // gyakorlatilag mindenhol kisbetűsen kezeli. Szigorú egyezéssel egy
        // `Erintett@example.test`-re tárolt sor egy `erintett@example.test`-re szóló törlési
        // kérésnek nem felelne meg: a parancs SIKERT jelentene, a cím bent maradna, és a
        // pixel vissza is írná. Ez a lista triviális megkerülése lenne.
        $email = $normalizer->email(trim((string) $this->argument('email')));
        $key = DomainNormalizer::listKey($email);

        if ($email === '' || ! str_contains($email, '@')) {
            $this->error('Érvénytelen e-mail-cím.');

            return self::FAILURE;
        }

        $redacted = ['messages' => 0, 'mime' => 0, 'events' => 0, 'smtp' => 0, 'attempts' => 0, 'webhooks' => 0, 'suppressions' => 0, 'inbound' => 0, 'validation' => 0, 'campaign_recipients' => 0, 'journey_runs' => 0, 'link_clicks' => 0];

        // SZÁRAZ FUTÁS: egy visszavonhatatlan, tenanteken átnyúló művelet előtt látni kell,
        // mit érintene. A tranzakció a végén visszagördül — a számok viszont valódiak.
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            DB::beginTransaction();
        }

        try {
            $this->workspaces()->chunkById(100, function ($workspaces) use ($context, $key, &$redacted): void {
                foreach ($workspaces as $workspace) {
                    $context->set($workspace);

                    /*
                     | A TARTÓS TILTÁS ELŐBB, minden redakció ELŐTT.
                     |
                     | Enélkül a kiküldött levélben lévő pixel egyetlen betöltéssel
                     | visszaírná a címet — és a `MessageEvent::created` hookja a webhookon
                     | harmadik félhez is kiküldené. Az Apple MPP és a Gmail képproxyja ezt
                     | felhasználói szándék nélkül, magától megteszi.
                     |
                     | MIÉRT ELSŐKÉNT: a valódi (nem `--dry-run`) futás NINCS egyetlen
                     | tranzakcióban — táblánként, illetve csoportonként dolgozik. Ha a
                     | sorrend végén állna, és közben bármi elhasal (deadlock, timeout a
                     | klikk-térkép ezres összevonásain), akkor a redakciók egy része már
                     | megtörtént, a visszaírás-védelem viszont NEM születik meg: a törlés
                     | részlegesen teljesül, és a pixel a maradékot visszahozza. Egyetlen
                     | sor beszúrása a legkisebb kockázatú lépés — az megy előre.
                     */
                    ErasedRecipient::query()->firstOrCreate(
                        ['recipient_hash' => ErasedRecipient::hash($key)],
                        ['erased_at' => now()],
                    );

                    $redacted['messages'] += $this->redactMessages($key, $redacted);
                    $redacted['events'] += $this->redactEvents($key);

                    $redacted['smtp'] += SmtpTransaction::query()
                        ->whereRaw('lower(recipient) = ?', [$key])
                        ->update(['recipient' => null, 'response_text' => null, 'lines' => [], 'redacted_at' => now()]);

                    $redacted['attempts'] += DeliveryAttempt::query()
                        ->whereRaw('lower(recipient) = ?', [$key])
                        ->update(['recipient' => null, 'response' => null, 'redacted_at' => now()]);

                    $redacted['webhooks'] += $this->redactWebhookDeliveries($key);
                    $redacted['suppressions'] += $this->redactSuppressions($key);
                    $redacted['inbound'] += $this->redactInbound($key);
                    $redacted['campaign_recipients'] += $this->redactCampaignRecipients($key);
                    $redacted['journey_runs'] += $this->redactJourneyRuns($key);
                    $redacted['link_clicks'] += $this->redactLinkClicks($key);

                    // A CÍMELLENŐRZÉS eredményei. Ez az ügyfél TELJES címlistája — a
                    // `PurgeValidationCommand` saját docblockja mondja ki, hogy ennél
                    // érzékenyebb adat kevés van a rendszerben. A 30 napos retenció nem
                    // válasz egy Art. 17 kérésre.
                    $redacted['validation'] += ValidationResult::query()
                        ->whereRaw('lower(address) = ?', [$key])
                        ->update(['address' => '(redaktált)', 'suggestion' => null, 'redacted_at' => now()]);

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
            ($dryRun ? '[SZÁRAZ FUTÁS] ' : '')
                .'Redaktálva — üzenet: %d, nyers MIME: %d, esemény: %d, SMTP-párbeszéd: %d, '
                .'kísérlet: %d, webhook-sor: %d, suppression: %d, bejövő levél: %d, '
                .'címellenőrzés: %d, kampány-címzett: %d, journey-futás: %d, klikk-sor: %d',
            $redacted['messages'],
            $redacted['mime'],
            $redacted['events'],
            $redacted['smtp'],
            $redacted['attempts'],
            $redacted['webhooks'],
            $redacted['suppressions'],
            $redacted['inbound'],
            $redacted['validation'],
            // A HÁROM ÚJ TÁBLA is a kimeneten van, nem csak az audit-payloadban: egy Art. 17
            // kérést futtató operátornak LÁTNIA kell, mi történt velük. A klikk-sor száma
            // épp az, ami korábban csendben nulla lett volna (a `ClickMap` nem normalizált
            // címet hashelt, tehát a redakció nem találta meg a sort).
            $redacted['campaign_recipients'],
            $redacted['journey_runs'],
            $redacted['link_clicks'],
        ));

        // RÉSZLEGES TÖRLÉS: az operátornak tudnia kell, hogy más workspace-eken a cím
        // megmaradt — és ott a kiküldött levelek pixelje vissza is írhatja.
        if (! $dryRun && ($only = (string) $this->option('workspace')) !== '') {
            $this->warn(sprintf(
                'FIGYELEM: a törlés CSAK a(z) %s workspace-re futott. Máshol a cím megmaradt, '
                .'és az ottani kiküldött levelek nyomkövetése továbbra is rögzíthet rá eseményt.',
                $only,
            ));
        }

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
    private function redactMessages(string $key, array &$redacted): int
    {
        $count = 0;

        Message::query()
            // Kis/nagybetű-független illesztés a jsonb-tömbön: a `whereJsonContains` PONTOS
            // egyezést vár, a tárolt alak viszont megőrzi a helyi rész kis/nagybetűit.
            ->whereRaw(
                'exists (select 1 from jsonb_array_elements_text(recipients) as r(value) where lower(r.value) = ?)',
                [$key],
            )
            // `eachById`, NEM `each`: az utóbbi OFFSET-tel lapoz, miközben a callback épp azt
            // a feltételt szünteti meg, amire szűrünk — a második lap offsetje már a maradék
            // halmazba mutatna, és sorok maradnának ki. Egy törlési kérésnél ez azt jelentené,
            // hogy „teljesítettük", miközben nem.
            ->eachById(function (Message $message) use ($key, &$count, &$redacted): void {
                $remaining = array_values(array_filter(
                    (array) $message->recipients,
                    fn ($recipient) => mb_strtolower((string) $recipient) !== $key,
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
                    // A `recipient-variables` KULCSA maga a cím, az értéke pedig a
                    // személyre szabás adata (név, ügyfél-azonosító) — pontosan az az
                    // adatosztály, amit az eseményekből is nullázunk.
                    'variables' => $this->withoutSubject((array) $message->variables, $key),
                    // A `h:` fejléc-felülírásokba a hívó bármit tehetett: `Reply-To`,
                    // `X-Ugyfel`, és bennük gyakran ott a cím.
                    'headers' => $this->withoutSubject((array) $message->headers, $key),
                    'redacted_at' => now(),
                ])->save();

                // A NYERS MIME akkor is megy, ha maradt címzett: a MIME egy bájtsor, amiből
                // egyetlen `To:` címet nem lehet sebészileg kivágni úgy, hogy közben a DKIM
                // és a szerkezet is ép maradjon. A megőrzés jogalapja pedig gyengébb, mint a
                // törlési kötelezettség — a kézbesítés tényét a `messages` sor őrzi.
                $redacted['mime'] += RawMimeMessage::query()
                    ->where('message_uuid', $message->uuid)
                    ->delete();

                $count++;
            }, 500);

        return $count;
    }

    /**
     * Egy tetszőleges mélységű tömbből MINDEN, a címet tartalmazó kulcs és érték eltűnik.
     *
     * Kulcs ÉS érték, mert a `recipient-variables`-ben a cím a KULCS (`{"a@b.c": {...}}`), a
     * `h:Reply-To`-ban pedig az ÉRTÉK. Aki csak az egyiket nézi, a másikat bent hagyja.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private function withoutSubject(array $data, string $key): array
    {
        $out = [];

        foreach ($data as $name => $value) {
            if (is_string($name) && str_contains(mb_strtolower($name), $key)) {
                continue;
            }

            if (is_array($value)) {
                $out[$name] = $this->withoutSubject($value, $key);

                continue;
            }

            if (is_string($value) && str_contains(mb_strtolower($value), $key)) {
                continue;
            }

            $out[$name] = $value;
        }

        return $out;
    }

    /**
     * A BEJÖVŐ levél (A4).
     *
     * Az érintett lehet a FELADÓ (ő írt nekünk) és a CÍMZETT is (neki írtak a mi
     * domainünkön). Mindkettőt nézzük — és a nyers MIME MEGY: abból egyetlen címet nem
     * lehet sebészileg kivágni úgy, hogy a szerkezet ép maradjon.
     *
     * A törzs és a csatolmány is megy: egy hozzánk írt levél TARTALMA maga a személyes adat,
     * és a megőrzésére a törlési kérés után nincs jogalapunk. A kézbesítés TÉNYE (mikor,
     * milyen státusszal) marad.
     */
    private function redactInbound(string $key): int
    {
        $count = 0;

        InboundMessage::query()
            ->where(fn (Builder $query) => $query
                ->whereRaw('lower(sender) = ?', [$key])
                ->orWhereRaw('lower(recipient) = ?', [$key]))
            ->whereNull('redacted_at')
            ->with('attachments')
            ->eachById(function (InboundMessage $message) use (&$count): void {
                // A FÁJLTÁRAT IS. Enélkül a nyers MIME és minden csatolmány (a karanténba
                // tett is) a tárolóban maradna, a `storage_key` nullázásával pedig az
                // `inbound:purge` sem találná meg többé — a fájlok VÉGLEGESEN árván
                // maradnának. Egy törlési kérésre „teljesítettük"-et jelentenénk, miközben a
                // legtöbb személyes adatot tartalmazó bájtok ott maradnak.
                $store = app(RawMailStore::class);

                $message->attachments->each(function (InboundAttachment $attachment) use ($store): void {
                    if (($attachment->storage_key ?? '') !== '') {
                        $store->delete((string) $attachment->storage_key);
                    }

                    $attachment->delete();
                });

                if (($message->storage_key ?? '') !== '') {
                    $store->delete((string) $message->storage_key);
                }

                $message->forceFill([
                    'sender' => null,
                    'recipient' => '(redaktált)',
                    'subject' => null,
                    'body_plain' => null,
                    'body_html' => null,
                    'stripped_text' => null,
                    'headers' => [],
                    'storage_key' => null,
                    'redacted_at' => now(),
                ])->save();

                $count++;
            }, 500);

        return $count;
    }

    /**
     * A SUPPRESSION-bejegyzés MARAD — a nyílt cím nem.
     *
     * A törlés itt ártana az érintettnek: a bejegyzés épp azt akadályozza meg, hogy továbbra
     * is levelet kapjon. A funkcióhoz viszont a nyílt cím nem kell — a keresés a kulcsolt
     * `email_hash`-en megy —, a `detail` pedig a fogadó nyers válaszát viszi tovább, amiben
     * gyakran szintén ott a cím, funkcionális ok nélkül.
     */
    private function redactSuppressions(string $key): int
    {
        return Suppression::query()
            ->whereRaw('lower(email) = ?', [$key])
            ->update(['email' => '(redaktált)', 'detail' => null]);
    }

    /**
     * A MATERIALIZÁLT KAMPÁNY-CÍMZETT — a sor marad, a cím nem.
     *
     * Ez a tábla a B2-vel jött, és SEMMI nem redaktálta: mérve az `erase()` után a
     * `contacts.email` már `torolt-…@erased.invalid` volt, a `campaign_recipients.email`-ben
     * viszont ott állt a NYERS cím. A tábla migrációjának kommentje azt állította, hogy a
     * cím „itt REDAKTÁLHATÓ (a `logs:redact-subject` útján)" — ez a metódus az, ami ezt
     * igazzá teszi.
     *
     * A SOR NEM TÖRÖLHETŐ: a kampány-riport („1000 címzett, 987 elküldve") visszamenőleg
     * megváltozna, ha egy törlési kérés kivenne belőle sorokat. Az `email_hash` marad — a
     * riporthoz elég (ugyanaz a cím-e), az azonosításhoz nem.
     */
    private function redactCampaignRecipients(string $key): int
    {
        return CampaignRecipient::query()
            ->whereRaw('lower(email) = ?', [$key])
            ->update(['email' => '(redaktált)', 'updated_at' => now()]);
    }

    /**
     * A JOURNEY-FUTÁS — a sor marad, a cím és a kontextus nem.
     *
     * A `journey_runs` a B3-mal jött, és semmi nem redaktálta: mérve az `erase()` után a
     * `contacts.email` már anonimizált volt, itt viszont ott állt a nyers cím, a
     * `context`-ben pedig a trigger nyers payloadja (űrlap-válaszok: telefonszám, cím).
     *
     * A FUTÓ futást is redaktáljuk: egy Art. 17 kérés nem várhat a journey végére. A cím
     * elvesztésével a futás nem tud tovább küldeni — ez helyes, hiszen az érintett épp a
     * törlést kérte.
     */
    private function redactJourneyRuns(string $key): int
    {
        return JourneyRun::query()
            ->whereRaw('lower(email) = ?', [$key])
            ->update(['email' => '(redaktált)', 'context' => '{}', 'updated_at' => now()]);
    }

    /**
     * A KLIKK-TÉRKÉP — a kattintások MEGMARADNAK, a KI eltűnik.
     *
     * A címzett itt már PSZEUDONIM (kulcsolt HMAC), nem nyers cím — de a pepper a miénk,
     * tehát a hash összekapcsolható, és egy Art. 17 kérés után nem tarthatjuk meg. Az
     * összevonás a `clicks` és az egyedi kattintók számát megőrzi: a kampány
     * klikk-térképe nem változik visszamenőleg attól, hogy valaki törlést kért.
     *
     * A sor TÖRLÉSE rossz válasz lenne: a fél évvel korábbi kampány riportja csökkenne, és
     * a tenant azt látná, hogy a számai maguktól romlanak.
     */
    private function redactLinkClicks(string $key): int
    {
        $hash = KeyedHash::of('email', $key);

        return app(ClickMap::class)->collapse(fn ($query) => $query->where('recipient_hash', $hash));
    }

    private function redactEvents(string $key): int
    {
        // Egyetlen UPDATE, nem soronkénti bejárás: a `message_events` triggere pontosan ezt
        // az alakot engedi át (PII-mezők nullázva + `redacted_at`), és így nincs olyan lap,
        // ami kimaradhat.
        return MessageEvent::query()
            ->whereRaw('lower(recipient) = ?', [$key])
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
    private function redactWebhookDeliveries(string $key): int
    {
        return WebhookDelivery::query()
            ->where(fn (Builder $query) => $query
                ->whereRaw('lower(payload::text) like ?', ['%'.$key.'%'])
                ->orWhereRaw('lower(response_excerpt) like ?', ['%'.$key.'%']))
            ->update(['payload' => [], 'response_excerpt' => null, 'redacted_at' => now()]);
    }
}
