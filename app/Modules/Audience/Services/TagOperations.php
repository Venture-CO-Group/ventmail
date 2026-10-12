<?php

declare(strict_types=1);

namespace App\Modules\Audience\Services;

use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\ContactTag;
use App\Modules\Audience\Models\Segment;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Journey\Models\Journey;
use App\Modules\Journey\Models\JourneyStep;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * CÍMKE-MŰVELETEK (v1.1 · 5. modul): tömeges címkézés, levétel, összevonás, hivatkozás-ellenőrzés.
 *
 * ## Halmaz-utasítás, nem ciklus
 *
 * A címkézés egy `INSERT … SELECT … ON CONFLICT DO NOTHING`, a levétel egy `DELETE … WHERE
 * contact_id IN (SELECT …)`. A kontakt-halmaz egy KONTAKT-lekérdezés (kijelölés, keresés vagy
 * szegmens), tehát a tenant-határt a `Contact` global scope-ja adja, a `workspace_id` pedig a
 * kontakt saját sorából jön — nem kérésből. 300 000 kontaktnál is egyetlen utasítás.
 *
 * ## Audit köteg-szinten, cím nélkül
 *
 * Mint az importnál (D31): egy százezres művelet soronkénti auditja a napló-táblát öntené
 * el, és személyes adatot vinne a SOSEM törölhető auditba. A köteg-sor: ki, melyik címkét,
 * milyen célra (kijelölés / keresés / szegmens), hány kontaktot érintett.
 *
 * ## Journey-trigger
 *
 * A tömeges címkézés NEM indít `tag_added` journey-t (mint az import címkéi sem): egy
 * százezres köteg egyszerre százezer futást és levelet indítana, a felhasználó szándéka
 * pedig a CÍMKÉZÉS, nem a beléptetés.
 */
final class TagOperations
{
    public function __construct(
        private readonly WorkspaceContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * A kontakt-halmaz MEGCÍMKÉZÉSE — az újonnan címkézettek számával.
     *
     * @param  Builder<Contact>  $contacts
     */
    public function assign(ContactTag $tag, Builder $contacts, string $target): int
    {
        $select = (clone $contacts)
            ->reorder()
            ->selectRaw('contacts.workspace_id, contacts.id, ?::bigint, now(), now()', [$tag->getKey()])
            ->toBase();

        $affected = DB::query()->from('contact_tag_assignments')->insertOrIgnoreUsing(
            ['workspace_id', 'contact_id', 'contact_tag_id', 'created_at', 'updated_at'],
            $select,
        );

        $this->log('tag', $tag, $target, $affected);

        return $affected;
    }

    /**
     * A címke LEVÉTELE a kontakt-halmazról.
     *
     * @param  Builder<Contact>  $contacts
     */
    public function remove(ContactTag $tag, Builder $contacts, string $target): int
    {
        $ids = (clone $contacts)->reorder()->select('contacts.id')->toBase();

        $affected = DB::query()->from('contact_tag_assignments')
            ->where('workspace_id', $this->context->workspaceId())
            ->where('contact_tag_id', $tag->getKey())
            ->whereIn('contact_id', $ids)
            ->delete();

        $this->log('untag', $tag, $target, $affected);

        return $affected;
    }

    /**
     * ÖSSZEVONÁS: a `$from` minden tagsága a `$into`-ba kerül, a `$from` törlődik.
     *
     * A SZEGMENS-hivatkozások ÁTÍRÓDNAK: egy törölt címkére mutató `not_has_tag` szabály
     * MINDENKIT beválasztana — a szegmens csendben a teljes közönségre szélesedne. A journey-
     * hivatkozást viszont nem írjuk át (a futó journey-k konfigurációja a felhasználó
     * jóváhagyott állapota) — azt a hívó előre elutasítja (`journeyReferences()`).
     *
     * @return int az átvitt (új) tagságok száma
     */
    public function merge(ContactTag $from, ContactTag $into): int
    {
        return DB::transaction(function () use ($from, $into): int {
            $moved = DB::query()->from('contact_tag_assignments')->insertOrIgnoreUsing(
                ['workspace_id', 'contact_id', 'contact_tag_id', 'created_at', 'updated_at'],
                DB::query()->from('contact_tag_assignments')
                    ->where('workspace_id', $this->context->workspaceId())
                    ->where('contact_tag_id', $from->getKey())
                    ->selectRaw('workspace_id, contact_id, ?::bigint, now(), now()', [$into->getKey()]),
            );

            $rewritten = 0;

            foreach ($this->segmentReferences($from) as $segment) {
                $segment->definition = $this->rewrite((array) $segment->definition, (string) $from->uuid, (string) $into->uuid);
                $segment->save();
                $rewritten++;
            }

            $this->audit->log('audience.tag.merged', $into, context: [
                'from' => (string) $from->uuid,
                'from_name' => (string) $from->name,
                'moved' => $moved,
                'segments_rewritten' => $rewritten,
            ]);

            // A forrás törlése a pivot-sorait is viszi (FK cascade) — auditált (`deleted`).
            $from->delete();

            return $moved;
        });
    }

    /**
     * A címkére hivatkozó szegmensek (bármely mélységben).
     *
     * @return list<Segment>
     */
    public function segmentReferences(ContactTag $tag): array
    {
        return Segment::query()
            ->whereRaw('definition::text like ?', ['%'.$tag->uuid.'%'])
            ->get()
            ->all();
    }

    /**
     * A címkére hivatkozó journey-k nevei (trigger, cél, lépés-konfiguráció).
     *
     * @return list<string>
     */
    public function journeyReferences(ContactTag $tag): array
    {
        $needle = '%'.$tag->uuid.'%';

        $direct = Journey::query()
            ->where(fn (Builder $query) => $query
                ->whereRaw('trigger_config::text like ?', [$needle])
                ->orWhereRaw('goal_config::text like ?', [$needle]))
            ->pluck('name');

        $viaSteps = Journey::query()
            ->whereIn('id', JourneyStep::query()->whereRaw('config::text like ?', [$needle])->select('journey_id'))
            ->pluck('name');

        return $direct->merge($viaSteps)->unique()->values()->map(fn ($name) => (string) $name)->all();
    }

    /**
     * A címke-uuid cseréje a szegmens-fában — csak a címke-szabályok ÉRTÉKÉBEN.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function rewrite(array $definition, string $from, string $into): array
    {
        $rules = [];

        foreach ((array) ($definition['rules'] ?? []) as $rule) {
            if (is_array($rule) && isset($rule['group']) && is_array($rule['group'])) {
                $rule['group'] = $this->rewrite($rule['group'], $from, $into);
            } elseif (is_array($rule) && in_array($rule['op'] ?? null, ['has_tag', 'not_has_tag'], true) && ($rule['value'] ?? null) === $from) {
                $rule['value'] = $into;
            }

            $rules[] = $rule;
        }

        $definition['rules'] = $rules;

        return $definition;
    }

    private function log(string $action, ContactTag $tag, string $target, int $affected): void
    {
        $this->audit->log('audience.tags.bulk', $tag, context: [
            'operation' => $action,
            'tag' => (string) $tag->uuid,
            'target' => $target,
            'affected' => $affected,
        ]);
    }
}
