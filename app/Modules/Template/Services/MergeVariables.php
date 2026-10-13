<?php

declare(strict_types=1);

namespace App\Modules\Template\Services;

use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\Segment;
use App\Modules\Audience\Services\SegmentEngine;
use App\Modules\StoredTemplates\Services\ConditionalContent;
use App\Modules\Tenancy\Models\Workspace;
use Throwable;

/**
 * A SABLON MERGE-VÁLTOZÓI egy kontaktra (v1.1 · merge-tag választó, feltételes blokk).
 *
 * A szerkesztő `{{ contact.first_name || … }}`, `{{ workspace.address }}` és szegmens-
 * feltételt ír a sablonba; a `TemplateRenderer` ezeket a küldéskor, címzettenként oldja fel.
 * Ez az osztály adja hozzá az ADATOT — egy helyen, hogy a kampány és (később) a journey
 * ugyanazt a kulcskészletet lássa, mint a választó.
 *
 * ## Mi kerül bele, és mi nem
 *
 * A kontakt neve, e-mail-címe, cége és egyéni attribútumai, a workspace neve és postai címe,
 * és a sablonban HIVATKOZOTT szegmensek közül azok, amelyeknek a kontakt tagja. A teljes
 * szegmenslista nem: egy 200 szegmenses workspace-ben az minden levélnél 200 lekérdezés
 * lenne, és a renderelés csak a hivatkozottakat kérdezi.
 *
 * A változók `render_variables`-ként utaznak: titkosítva tárolódnak, és a kézbesítés után
 * törlődnek — a személyes adat nem kerül az esemény-naplóba.
 */
final class MergeVariables
{
    /** @var array<string, array<int, true>> szegmens-uuid → a tag kontakt-id-k */
    private array $membership = [];

    /** @var list<string> a sablonban hivatkozott `contact.attributes.*` kulcsok */
    private array $attributes = [];

    public function __construct(private readonly SegmentEngine $segments) {}

    /**
     * A hivatkozott szegmensek tagságának ELŐKÉSZÍTÉSE egy kötegre (egy lekérdezés
     * szegmensenként, nem címzettenként).
     *
     * @param  list<string>  $contents  a sablonok HTML-je / szövege
     * @param  list<int>  $contactIds
     */
    public function prepare(array $contents, array $contactIds): void
    {
        $this->membership = [];
        $this->attributes = [];

        $referenced = [];

        foreach ($contents as $content) {
            $referenced = [...$referenced, ...ConditionalContent::referencedSegments($content)];

            // ADATMINIMALIZÁLÁS: csak a sablonban HIVATKOZOTT egyéni mezők utaznak tovább
            // (merge-tag vagy feltétel), nem a kontakt teljes attribútum-készlete.
            preg_match_all('/\{\{\s*contact\.attributes\.([a-z0-9_]{1,64})/', $content, $tags);
            $this->attributes = [...$this->attributes, ...($tags[1] ?? []), ...ConditionalContent::referencedAttributes($content)];
        }

        $this->attributes = array_values(array_unique($this->attributes));
        sort($this->attributes);

        $referenced = array_values(array_unique($referenced));

        if ($referenced === [] || $contactIds === []) {
            return;
        }

        // DETERMINISZTIKUS sorrend (B1): ugyanaz a köteg ugyanazt a változó-halmazt adja.
        foreach (Segment::query()->whereIn('uuid', $referenced)->orderBy('uuid')->get() as $segment) {
            try {
                $ids = $this->segments->query((array) $segment->definition)
                    ->whereIn('contacts.id', $contactIds)
                    ->pluck('contacts.id')
                    ->all();
            } catch (Throwable) {
                // A HIBÁS szegmens-definíció NEM tagság: a feltételes blokk rejtve marad, a
                // levél kimegy. Egy „csak VIP-eknek" tartalom nem kerülhet mindenkihez.
                $ids = [];
            }

            $this->membership[strtolower((string) $segment->uuid)] = array_fill_keys(array_map('intval', $ids), true);
        }
    }

    /** @return array<string, mixed> */
    public function forContact(?Contact $contact, Workspace $workspace): array
    {
        $attributes = $contact === null ? [] : (array) $contact->attributes;
        $segments = [];

        if ($contact !== null) {
            foreach ($this->membership as $uuid => $members) {
                if (isset($members[(int) $contact->getKey()])) {
                    $segments[] = $uuid;
                }
            }
        }

        return [
            'contact' => [
                'first_name' => (string) ($contact?->first_name ?? ''),
                'last_name' => (string) ($contact?->last_name ?? ''),
                'email' => (string) ($contact?->email ?? ''),
                'company' => is_scalar($attributes['company'] ?? null) ? (string) $attributes['company'] : '',
                'attributes' => $this->referencedAttributes($attributes),
                'segments' => $segments,
            ],
            'workspace' => [
                'name' => (string) $workspace->name,
                'address' => (string) (($workspace->settings ?? [])['postal_address'] ?? ''),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, scalar|null>
     */
    private function referencedAttributes(array $attributes): array
    {
        $out = [];

        foreach ($this->attributes as $key) {
            $value = $attributes[$key] ?? null;
            $out[$key] = is_scalar($value) ? $value : null;
        }

        return $out;
    }
}
