<?php

declare(strict_types=1);

namespace App\Modules\Audience\Services;

use App\Modules\Audience\Contracts\VentureOsSync;
use App\Modules\Audience\Data\CrmContact;
use App\Modules\Audience\Models\Contact;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Logs\Models\ErasedRecipient;
use App\Modules\Sending\Services\DomainNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * KÉTIRÁNYÚ, IDEMPOTENS kontakt-szinkron (56. kritérium).
 *
 * ## Az idempotencia követelménye
 *
 * A szinkron hálózaton megy, tehát ÚJRAPRÓBÁLÓDIK. Ha a második futás duplikálna, egy
 * megszakadt szinkron után a lista megkétszereződne — és a kampány mindenkinek kétszer
 * mennne ki. Ezért a kulcs az `external_id`, és az írás `updateOrCreate`: ugyanaz a
 * bemenet kétszer ugyanazt az EGY sort adja.
 *
 * ## Last-write-wins, és miért ez
 *
 * Ütközésnél az `updated_at` későbbi oldala nyer. A mezőnkénti összefésülés elvben jobb,
 * a gyakorlatban viszont „félig frissült" kontaktokat gyárt: a keresztnév az egyik
 * rendszerből, a város a másikból, és senki nem tudja, melyik az igazság. Egy címlistánál
 * az egyértelműség többet ér.
 *
 * ## Amit a szinkron SOHA nem ír át
 *
 * A CONSENT-állapotot és a leiratkozást. A ventOS egy CRM: nem tudja, és nem is
 * jogosult tudni, hogy valaki leiratkozott a hírlevélről. Ha a szinkron felülírhatná a
 * `status`-t, egy CRM-oldali „aktív ügyfél" flag visszahozná a leiratkozott címet a
 * listára — vagyis a leiratkozás megkerülhető lenne egy másik rendszerből (golden rule 4).
 */
final class ContactSynchroniser
{
    public function __construct(private readonly VentureOsSync $sync) {}

    /**
     * BEHÚZÁS a ventOS-ből.
     *
     * @return array{created: int, updated: int, skipped: int}
     */
    public function pull(?string $since = null): array
    {
        $summary = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($this->sync->pull($since) as $remote) {
            $email = DomainNormalizer::listKey(trim($remote->email));

            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || trim($remote->externalId) === '') {
                $summary['skipped']++;

                continue;
            }

            /*
             | A GDPR-TÖRLÉS ERŐSEBB A CRM-NÉL.
             |
             | Mérve, mit jelentett a hiánya: `erase()` után (a kontakt soft delete-elve, a
             | cím anonimizálva) egy behúzás ugyanazzal az `external_id`-val ÚJ, ÉLŐ kontaktot
             | hozott létre — a NYERS címmel. Vagyis a CRM feltámasztotta azt, akinek a
             | törlését épp teljesítettük, és a 17. cikk szerinti kérés eredménye az lett,
             | hogy az érintett adata visszakerült a rendszerbe.
             |
             | Az `ErasedRecipient` a platform-szintű sírkő (A3): pont ezért létezik, és a
             | törlési út írja. A szinkron ezért NEM hozhat vissza olyan címet, ami rajta van.
             */
            if (ErasedRecipient::erased($email)) {
                $summary['skipped']++;

                continue;
            }

            DB::transaction(function () use ($remote, $email, &$summary): void {
                // A kulcs az `external_id`; ha az még nincs, a CÍM azonosít (egy már
                // meglévő kontakt kapja meg a CRM-azonosítót, nem duplikálódik).
                $contact = Contact::query()->where('external_id', $remote->externalId)->first()
                    ?? Contact::query()->where('email', $email)->first();

                if ($contact === null) {
                    Contact::query()->create([
                        'email' => $email,
                        'external_id' => $remote->externalId,
                        'first_name' => $remote->firstName,
                        'last_name' => $remote->lastName,
                        'attributes' => $remote->attributes,
                        // Az importált kontakt `pending`: a CRM-ből érkező cím NEM jelent
                        // hozzájárulást (`ConsentRecorder::imported()` dokumentálja, miért).
                        'status' => 'pending',
                        // v1.1 · forrás: a ventOS / API-szinkron (a dashboard „API" sávja).
                        'source' => 'api',
                        'last_synced_at' => now(),
                    ]);

                    $summary['created']++;

                    return;
                }

                // LAST-WRITE-WINS: ha a helyi sor FRISSEBB, a távoli nem írja felül.
                if ($remote->updatedAt !== null
                    && $contact->updated_at !== null
                    && $contact->updated_at->gt($remote->updatedAt)) {
                    $summary['skipped']++;

                    return;
                }

                $contact->fill([
                    'external_id' => $remote->externalId,
                    'first_name' => $remote->firstName ?? $contact->first_name,
                    'last_name' => $remote->lastName ?? $contact->last_name,
                    'attributes' => array_merge((array) $contact->attributes, $remote->attributes),
                    'last_synced_at' => now(),
                ]);

                // A `status` SZÁNDÉKOSAN nincs a `fill`-ben: a CRM nem írhatja át a
                // leiratkozást (lásd az osztály docblockját).
                $contact->save();

                $summary['updated']++;
            });
        }

        $this->audit('pull', $summary);

        return $summary;
    }

    /**
     * KITOLÁS a ventOS-be — csak a `external_id`-val rendelkező, MÓDOSULT kontaktok.
     *
     * @return array{pushed: int}
     */
    public function push(?string $since = null): array
    {
        $pushed = 0;

        // KÖTEGENKÉNT PUSH-OLUNK, nem a végén egyszer.
        //
        // A `chunkById` értelmetlen lett volna, ha a `$payload` közben a TELJES listát
        // memóriában gyűjti: egy milliós címlistán az OOM — a kötegelés pont ezt hivatott
        // megelőzni. Így a memóriahasználat a kötegmérettől függ, nem a lista hosszától.
        Contact::query()
            ->whereNotNull('external_id')
            // CSAK az ELÉRHETŐ kontaktok mennek ki a CRM-be.
            //
            // A leiratkozott és a törölt (`cleaned`) cím nyers alakját nem adjuk át egy
            // külső rendszernek: a leiratkozás után nincs jogalapunk a további
            // adattovábbításra, és a CRM-oldali „aktív ügyfél" flag amúgy is épp ezeket
            // hozná vissza.
            ->reachable()
            ->when($since !== null, fn ($query) => $query->where('updated_at', '>', $since))
            ->orderBy('id')
            ->chunkById((int) config('audience.sync_batch', 500), function ($contacts) use (&$pushed): void {
                $batch = [];

                foreach ($contacts as $contact) {
                    $batch[] = new CrmContact(
                        externalId: (string) $contact->external_id,
                        email: (string) $contact->email,
                        firstName: $contact->first_name,
                        lastName: $contact->last_name,
                        attributes: (array) $contact->attributes,
                        updatedAt: $contact->updated_at,
                    );
                }

                if ($batch !== []) {
                    $pushed += $this->sync->push($batch);
                }
            });

        $this->audit('push', ['pushed' => $pushed]);

        return ['pushed' => $pushed];
    }

    /**
     * A szinkron AUDITÁLT (golden rule 11).
     *
     * Nyers cím NEM kerül bele — darabszám elég. Egy szinkron-futás naplósora attól
     * hasznos, hogy megmondja, mikor mennyi sor mozdult; a címek felsorolása egy
     * törölhetetlen táblába öntené a teljes listát.
     *
     * @param  array<string, int>  $summary
     */
    private function audit(string $direction, array $summary): void
    {
        AuditLog::query()->create([
            'action' => 'audience.sync.'.$direction,
            'auditable_type' => Contact::class,
            'context' => $summary,
        ]);
    }
}
