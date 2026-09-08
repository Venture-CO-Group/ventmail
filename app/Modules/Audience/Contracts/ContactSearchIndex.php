<?php

declare(strict_types=1);

namespace App\Modules\Audience\Contracts;

use App\Modules\Audience\Models\Contact;

/**
 * A kontakt-KERESŐINDEX (49. kritérium).
 *
 * Interfész mögött, mert külső gazda (Meilisearch), és mert a keresés SOHA nem lehet a
 * helyes működés előfeltétele: ha a Meili nem elérhető, a kontakt mentése akkor is menjen
 * végbe. Ezért az indexelés ASZINKRON, jobban fut, és a hibája nem hat vissza az írásra.
 *
 * A tenant-izoláció az indexben is kötelező: a dokumentum `workspace_id`-t hordoz, és a
 * keresés SZŰR rá. Egy közös index tenant-szűrő nélkül a legrosszabb fajta szivárgás lenne —
 * a felület kereső-mezőjéből azonnal látszana idegen ügyfél címlistája (golden rule 6).
 */
interface ContactSearchIndex
{
    public function index(Contact $contact): void;

    public function remove(Contact $contact): void;

    /**
     * @return list<string> a találatok kontakt-uuid-jai, RELEVANCIA szerint
     */
    public function search(int $workspaceId, string $query, int $limit = 50): array;
}
