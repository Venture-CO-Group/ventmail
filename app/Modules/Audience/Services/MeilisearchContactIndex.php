<?php

declare(strict_types=1);

namespace App\Modules\Audience\Services;

use App\Modules\Audience\Contracts\ContactSearchIndex;
use App\Modules\Audience\Models\Contact;
use Meilisearch\Client;
use Meilisearch\Endpoints\Indexes;
use Throwable;

/**
 * A kontakt-index Meilisearchen (49. kritérium).
 *
 * ## A TENANT-SZŰRŐ az index szintjén
 *
 * A dokumentum `workspace_id`-t hordoz, és a keresés `filter`-rel szűr rá. Ez nem
 * kényelmi elem: egy közös index tenant-szűrő nélkül azt jelentené, hogy a felület
 * kereső-mezőjébe „gmail" beírásával bárki idegen ügyfél címlistáját látja. A
 * `filterableAttributes` beállítása ezért az index létrehozásának RÉSZE — szűrhető
 * attribútum nélkül a Meili a `filter`-t hibával utasítja el, és a fail-closed viselkedés
 * (nincs találat) jobb, mint a szűrés nélküli keresés.
 *
 * ## Miért interfész, és nem a Scout `Searchable` traitje
 *
 * A `laravel/scout` a projektben elérhető, és a trait kevesebb kóddal járna. A KERESÉS
 * viszont itt tenant-szűrő NÉLKÜL katasztrófa: a Scout `search()`-e alapból a teljes
 * indexet kérdezi, tehát a szűrőt MINDEN hívási helyen emlékezetből kellene odaírni. Az A6
 * négy review-köre pontosan ezt tanította meg: ami minden hívási helyen a fejlesztő
 * figyelmén múlik, az előbb-utóbb kimarad valahol. Itt a szűrő a KONTRAKTUS része — a
 * `search()` első paramétere a workspace, tehát nem lehet elfelejteni.
 *
 * ## Miért nem tárolunk címet az indexben
 *
 * Tárolunk — muszáj, mert a felhasználó CÍMRE keres. De az index egy KÜLSŐ rendszer, tehát
 * a GDPR-törlésnek oda is el kell érnie: a `remove()` a törlési út része, nem opcionális
 * kiegészítés.
 */
final class MeilisearchContactIndex implements ContactSearchIndex
{
    private const INDEX = 'contacts';

    /** Kérésen belül egyszer ellenőrizzük a beállítást (lásd `ensureFilterable()`). */
    private bool $filterableEnsured = false;

    public function __construct(private readonly Client $client) {}

    public function index(Contact $contact): void
    {
        $this->guarded(function () use ($contact): void {
            $this->ensureFilterable();

            $this->documents()->addDocuments([[
                'id' => (string) $contact->uuid,
                'workspace_id' => (int) $contact->workspace_id,
                'email' => (string) $contact->email,
                'first_name' => (string) $contact->first_name,
                'last_name' => (string) $contact->last_name,
                'status' => (string) $contact->status,
            ]], 'id');
        });
    }

    public function remove(Contact $contact): void
    {
        $this->guarded(fn () => $this->documents()->deleteDocument((string) $contact->uuid));
    }

    public function search(int $workspaceId, string $query, int $limit = 50): array
    {
        $hits = $this->guarded(function () use ($query, $workspaceId, $limit) {
            $this->ensureFilterable();

            return $this->documents()->search($query, [
                // A SZŰRŐ nem opcionális: enélkül a keresés minden tenant kontaktját
                // visszaadná. A `ensureFilterable()` gondoskodik arról, hogy a Meili
                // egyáltalán engedje ezt a szűrőt.
                'filter' => 'workspace_id = '.$workspaceId,
                'limit' => max(1, min($limit, 200)),
                'attributesToRetrieve' => ['id'],
            ])->getHits();
        }, []);

        return array_values(array_map(static fn (array $hit) => (string) $hit['id'], (array) $hits));
    }

    /** Az index-végpont. (Nem `index()`, hogy ne ütközzön a kontraktus metódusával.) */
    private function documents(): Indexes
    {
        return $this->client->index(self::INDEX);
    }

    /**
     * A SZŰRHETŐ ATTRIBÚTUM BEÁLLÍTÁSA — enélkül a tenant-szűrő SOHA nem fut le.
     *
     * A korábbi docblock azt állította, hogy „a `filterableAttributes` beállítása az index
     * létrehozásának RÉSZE" — nem volt kód, ami beállította volna. A review megmérte az élő
     * dev-indexen: `filterableAttributes: []`, és a szűrt keresés
     * `Attribute `workspace_id` is not filterable`-lel elhasalt, a szűrő NÉLKÜLI keresés
     * viszont három workspace kontaktjait adta EGY találati listában.
     *
     * Ma ez fail-closed (a `guarded()` elnyeli, üres lista a válasz), tehát a keresés
     * élesben egyszerűen nem működik — és épp ez a veszélyes: a látható szimptóma „nem talál
     * semmit", amire a legrövidebb látszat-javítás a `filter` KIVÉTELE lenne. Onnantól a
     * keresőmező minden tenant címlistáját visszaadja.
     *
     * A beállítás IDEMPOTENS és csak akkor ír, ha kell: a Meili minden `updateSettings`
     * hívásra új taskot indít, és egy kontaktonkénti beállítás-frissítés a
     * task-sort tömné meg.
     */
    private function ensureFilterable(): void
    {
        if ($this->filterableEnsured) {
            return;
        }

        $this->filterableEnsured = true;

        $current = (array) ($this->documents()->getSettings()['filterableAttributes'] ?? []);

        if (! in_array('workspace_id', $current, true)) {
            $this->documents()->updateFilterableAttributes(
                array_values(array_unique([...$current, 'workspace_id']))
            );
        }
    }

    /**
     * A KERESÉS SOHA nem törheti el az írást.
     *
     * Egy Meili-kimaradás alatt a kontakt mentése menjen végbe; a hiányzó indexelést a
     * `audience:reindex` pótolja. A kivétel elnyelése itt tudatos, és NEM csendes: a hívó
     * (a job) naplózza — de a felhasználó felé egy keresőindex hibája nem lehet
     * „a mentés nem sikerült".
     */
    private function guarded(callable $work, mixed $fallback = null): mixed
    {
        try {
            return $work();
        } catch (Throwable $e) {
            report($e);

            return $fallback;
        }
    }
}
