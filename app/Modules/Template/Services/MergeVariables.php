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

        $referenced = [];

        foreach ($contents as $content) {
            $referenced = [...$referenced, ...ConditionalContent::referencedSegments($content)];
        }

        $referenced = array_values(array_unique($referenced));

        if ($referenced === [] || $contactIds === []) {
            return;
        }

        foreach (Segment::query()->whereIn('uuid', $referenced)->get() as $segment) {
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
                'attributes' => array_filter($attributes, static fn ($value) => is_scalar($value) || $value === null),
                'segments' => $segments,
            ],
            'workspace' => [
                'name' => (string) $workspace->name,
                'address' => (string) (($workspace->settings ?? [])['postal_address'] ?? ''),
            ],
        ];
    }
}
