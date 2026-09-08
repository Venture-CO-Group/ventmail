<?php

declare(strict_types=1);

namespace App\Modules\Audience\Services;

use App\Modules\Audience\Contracts\VentureOsSync;
use App\Modules\Audience\Data\CrmContact;
use App\Modules\Audience\Models\Contact;
use App\Modules\Audit\Models\AuditLog;
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
        $payload = [];

        Contact::query()
            ->whereNotNull('external_id')
            ->when($since !== null, fn ($query) => $query->where('updated_at', '>', $since))
            ->orderBy('id')
            ->chunkById((int) config('audience.sync_batch', 500), function ($contacts) use (&$payload): void {
                foreach ($contacts as $contact) {
                    $payload[] = new CrmContact(
                        externalId: (string) $contact->external_id,
                        email: (string) $contact->email,
                        firstName: $contact->first_name,
                        lastName: $contact->last_name,
                        attributes: (array) $contact->attributes,
                        updatedAt: $contact->updated_at,
                    );
                }
            });

        $pushed = $payload === [] ? 0 : $this->sync->push($payload);

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
