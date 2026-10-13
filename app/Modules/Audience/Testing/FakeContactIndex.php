<?php

declare(strict_types=1);

namespace App\Modules\Audience\Testing;

use App\Modules\Audience\Contracts\ContactSearchIndex;
use App\Modules\Audience\Models\Contact;

/**
 * Determinisztikus kontakt-index a tesztekhez.
 *
 * A `documents` tömb a KONTRAKTUS ellenőrzésére van: a teszt megnézheti, hogy a mentés
 * tényleg indexelt-e, és hogy a GDPR-törlés tényleg eltávolította-e a dokumentumot. A
 * keresés a tenant-szűrőt is ELVÉGZI — különben a fake pont azt a hibát takarná el, ami a
 * legsúlyosabb lenne (idegen tenant kontaktja a találatok között).
 */
final class FakeContactIndex implements ContactSearchIndex
{
    /** @var array<string, array<string, mixed>> */
    public array $documents = [];

    public function index(Contact $contact): void
    {
        $this->documents[(string) $contact->uuid] = [
            'workspace_id' => (int) $contact->workspace_id,
            'email' => (string) $contact->email,
            'name' => trim((string) $contact->first_name.' '.(string) $contact->last_name),
            'status' => (string) $contact->status,
        ];
    }

    public function indexMany(iterable $contacts): void
    {
        foreach ($contacts as $contact) {
            $this->index($contact);
        }
    }

    public function remove(Contact $contact): void
    {
        unset($this->documents[(string) $contact->uuid]);
    }

    public function search(int $workspaceId, string $query, int $limit = 50): array
    {
        $needle = mb_strtolower(trim($query));

        $hits = [];

        foreach ($this->documents as $uuid => $document) {
            // A TENANT-SZŰRŐ a fake-ben is lefut: különben a teszt zöld lenne egy olyan
            // implementáció mellett is, ami idegen tenant kontaktjait adja vissza.
            if ($document['workspace_id'] !== $workspaceId) {
                continue;
            }

            $haystack = mb_strtolower($document['email'].' '.$document['name']);

            if ($needle === '' || str_contains($haystack, $needle)) {
                $hits[] = (string) $uuid;
            }
        }

        return array_slice($hits, 0, $limit);
    }
}
