<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Services;

use App\Modules\Audience\Models\ContactList;
use App\Modules\Audience\Models\Segment;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Consent\Models\Consent;
use App\Modules\Domains\Models\Domain;
use App\Modules\Sending\Services\DomainNormalizer;
use App\Modules\Template\Models\Template;
use App\Modules\Template\Services\TemplateCompliance;
use App\Support\QueryBudget;
use App\Support\QueryTooExpensive;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * A KAMPÁNY CHECKLIST (v1.1 · 5. modul) — szakaszok + szerver-oldali előellenőrzés.
 *
 * ## Mit mond ki
 *
 * Hat szakasz (címzettek, feladó, tárgy + preheader, tartalom, ütemezés, A/B), mindegyik
 * kész / hiányos, és három SZERVER-ellenőrzés, amelyek nélkül a küldés gombja nem él:
 *
 *  - **compliance** — a `TemplateCompliance` SZIGORÚ módban (lábléc + VALÓDI postai cím +
 *    leiratkozó + beállítások) a kampány és minden variáns sablonján (D43/D64);
 *  - **domain** — a kampány és minden variáns feladó-domainje hitelesített (golden rule 8);
 *  - **consent** — a célzott közönségben van MEGERŐSÍTETT marketing-hozzájárulású címzett
 *    (golden rule 4). A többit a PreSendGate 3. kapuja a küldéskor elutasítja — a szám
 *    ezt előre megmondja.
 *
 * ## Keretezett feloldás (review SN3)
 *
 * A becslés és a consent-metszet a felhasználó által összerakott szegmenseket oldja fel — egy
 * webkérésben. Mindkettő `QueryBudget` alatt fut (5 mp); túllépésnél az érték `null`, a jelző
 * `estimateUnavailable` / `unavailable`, és a felület „becslés nem elérhető"-t mutat. Ez NEM
 * blokkolja a küldést: a becslés előrejelzés, a materializálás (háttérben, keret nélkül) és a
 * PreSendGate címzettenként dönt. Az ütemező (`failingChecks`, CLI) keret nélkül számol.
 *
 * ## Nem kapu, hanem előrejelzés
 *
 * A checklist a felület és az indítás ELŐFELTÉTELE (a kontroller az indításnál ugyanezt
 * kérdezi), de a tényleges döntést a küldés pillanatában a PreSendGate hozza — címzettenként,
 * a küldési mód (seed-allowlist, marketing-kapcsoló) szerint is. Ezekhez itt nem nyúlunk,
 * csak MEGMUTATJUK az állapotukat.
 */
final class CampaignChecklist
{
    /** A tárgy ajánlott hossza (karakter) — csak jelzés, nem feltétel. */
    public const SUBJECT_RECOMMENDED = [30, 60];

    /** A consent-metszet gyorsítótárának élettartama (mp). */
    public const CONSENT_CACHE_SECONDS = 120;

    public function __construct(
        private readonly AudienceResolver $audience,
        private readonly TemplateCompliance $compliance,
        private readonly QueryBudget $budget,
    ) {}

    /**
     * @return array{
     *     sections: list<array<string, mixed>>,
     *     checks: list<array<string, mixed>>,
     *     ready: bool,
     *     blockers: list<string>,
     *     sendingMode: array<string, bool>
     * }
     */
    public function evaluate(Campaign $campaign): array
    {
        $campaign->loadMissing(['template', 'variants.template']);

        $estimate = $this->budgeted(fn (): int => $this->estimate($campaign));
        $domain = $this->domainCheck($campaign);
        $compliance = $this->complianceCheck($campaign);
        $consent = $this->consentCheck($campaign, $estimate, budgeted: true);

        $sections = [
            $this->recipients($campaign, $estimate),
            $this->sender($campaign),
            $this->subject($campaign),
            $this->content($campaign, $compliance),
            $this->schedule($campaign),
            $this->ab($campaign),
        ];

        $checks = [$domain, $compliance, $consent];

        $blockers = [
            ...array_map(static fn (array $section) => 'section:'.$section['key'], array_filter($sections, static fn (array $s) => ! $s['done'])),
            ...array_map(static fn (array $check) => 'check:'.$check['key'], array_filter($checks, static fn (array $c) => ! $c['ok'])),
        ];

        return [
            'sections' => $sections,
            'checks' => $checks,
            'ready' => $blockers === [] && $campaign->statusEnum()->isEditable(),
            'blockers' => array_values($blockers),
            // CSAK MEGJELENÍTÉS: a küldési mód állapota (a tulajdonos kapcsolja, nem mi).
            'sendingMode' => [
                'live' => (bool) config('sending.live'),
                'marketingEnabled' => (bool) config('sending.marketing_enabled'),
                'allowlistEnforced' => ! (bool) config('sending.live') || (bool) config('sending.allowlist_enforced', true),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function recipients(Campaign $campaign, ?int $estimate): array
    {
        $lists = ContactList::query()->whereIn('uuid', $this->uuids($campaign->target_lists))->pluck('name')->all();
        $segments = Segment::query()->whereIn('uuid', $this->uuids($campaign->target_segments))->pluck('name')->all();

        return [
            'key' => 'recipients',
            // Ismeretlen (keret-túllépés) becslésnél a célzás megléte dönt — lásd az osztály docblockját.
            'done' => ($lists !== [] || $segments !== []) && ($estimate === null || $estimate > 0),
            'lists' => $lists,
            'segments' => $segments,
            'excluded' => count($this->uuids($campaign->exclude_lists)) + count($this->uuids($campaign->exclude_segments)),
            'estimate' => $estimate,
            'estimateUnavailable' => $estimate === null,
        ];
    }

    /** @return array<string, mixed> */
    private function sender(Campaign $campaign): array
    {
        $email = trim((string) $campaign->from_email);
        $verified = $email !== '' && $this->isVerified($email);

        return [
            'key' => 'sender',
            'done' => $verified,
            'fromEmail' => $email === '' ? null : $email,
            'fromName' => $campaign->from_name,
            'domain' => $email === '' ? null : DomainNormalizer::ofEmail($email),
            'verified' => $verified,
        ];
    }

    /** @return array<string, mixed> */
    private function subject(Campaign $campaign): array
    {
        $subject = trim((string) $campaign->subject);

        return [
            'key' => 'subject',
            'done' => $subject !== '',
            'subject' => $subject,
            'preheader' => $campaign->preheader,
            'length' => mb_strlen($subject),
            'recommended' => self::SUBJECT_RECOMMENDED,
        ];
    }

    /**
     * @param  array<string, mixed>  $compliance
     * @return array<string, mixed>
     */
    private function content(Campaign $campaign, array $compliance): array
    {
        $template = $campaign->template;

        return [
            'key' => 'content',
            'done' => $template !== null && $compliance['ok'],
            'template' => $template === null ? null : [
                'uuid' => (string) $template->uuid,
                'name' => (string) $template->name,
                'marketing' => (bool) $template->marketing,
                // UI-polírozás · 6: a kampány SAJÁT (helyben készült) tartalma-e.
                'owned' => (int) $template->owner_campaign_id === (int) $campaign->getKey(),
            ],
            'missing' => $compliance['missing'],
        ];
    }

    /** @return array<string, mixed> */
    private function schedule(Campaign $campaign): array
    {
        $mode = (string) ($campaign->send_mode ?? '');
        $timezone = (string) $campaign->timezone;
        $at = null;

        if ($mode === 'later' && is_string($campaign->scheduled_local) && $campaign->scheduled_local !== ''
            && in_array($timezone, timezone_identifiers_list(), true)) {
            try {
                $at = Carbon::parse($campaign->scheduled_local, $timezone);
            } catch (Throwable) {
                $at = null;
            }
        }

        return [
            'key' => 'schedule',
            'done' => $mode === 'now' || ($at !== null && $at->isFuture()),
            'mode' => $mode === '' ? null : $mode,
            'scheduledLocal' => $campaign->scheduled_local,
            'timezone' => $timezone,
            'past' => $at !== null && ! $at->isFuture(),
        ];
    }

    /**
     * A/B — OPCIONÁLIS: variáns nélkül kész; variánsokkal akkor, ha legalább kettő van, a
     * súlyok összege 100, és a győztes-kritérium beállított.
     *
     * @return array<string, mixed>
     */
    private function ab(Campaign $campaign): array
    {
        $count = $campaign->variants->count();
        $total = (int) $campaign->variants->sum('weight');

        return [
            'key' => 'ab',
            'optional' => true,
            'done' => $count === 0 || ($count >= 2 && $total === 100 && $campaign->winner_criterion !== null),
            'variants' => $count,
            'weightTotal' => $total,
            'criterion' => $campaign->winner_criterion,
            'afterMinutes' => (int) $campaign->winner_after_minutes,
        ];
    }

    /** @return array<string, mixed> */
    private function domainCheck(Campaign $campaign): array
    {
        $addresses = array_values(array_unique(array_filter([
            trim((string) $campaign->from_email),
            ...$campaign->variants->map(fn ($variant) => trim((string) $variant->from_email))->all(),
        ])));

        $unverified = array_values(array_filter($addresses, fn (string $email) => ! $this->isVerified($email)));

        return [
            'key' => 'domain',
            'ok' => $addresses !== [] && $unverified === [],
            'unverified' => array_map(static fn (string $email) => DomainNormalizer::ofEmail($email), $unverified),
        ];
    }

    /** @return array<string, mixed> */
    private function complianceCheck(Campaign $campaign): array
    {
        $templates = collect([$campaign->template, ...$campaign->variants->map(fn ($variant) => $variant->template)->all()])
            ->filter()
            ->unique(fn (Template $template) => $template->getKey());

        $missing = [];

        foreach ($templates as $template) {
            // A TRANZAKCIÓS sablon nem kampány-sablon: a kampány csak marketing lehet.
            if (! $template->marketing) {
                $missing[] = 'marketing_template';

                continue;
            }

            foreach ($this->compliance->missing((array) $template->blocks, strict: true) as $item) {
                $missing[] = $item;
            }
        }

        $missing = array_values(array_unique($missing));

        return [
            'key' => 'compliance',
            'ok' => $templates->isNotEmpty() && $missing === [],
            'missing' => $missing,
        ];
    }

    /**
     * A MEGERŐSÍTETT marketing-hozzájárulású címzettek száma a célzásban.
     *
     * Ugyanaz a célzás-lekérdezés, amelyik a küldéskor materializálódik — a szám tehát nem
     * becslés-formula, hanem a valódi halmaz metszete a `consents` táblával (tenant-szűrten).
     *
     * @return array<string, mixed>
     */
    private function consentCheck(Campaign $campaign, ?int $estimate, bool $budgeted = false): array
    {
        if ($estimate === null) {
            return ['key' => 'consent', 'ok' => true, 'unavailable' => true, 'confirmed' => null, 'total' => null];
        }

        // GYORSÍTÓTÁR a célzás lenyomatára (review RS4): a checklist minden mentés után újra
        // kiértékelődik, a metszet-számlálás viszont egy 300k-s célzásnál drága. A kulcs a
        // workspace + a célzás (listák, szegmensek, kizárások) — más célzás más kulcs, tehát a
        // szám a célzás változásakor azonnal friss; a consent-állapot változását 2 percen belül
        // követi (a küldéskor úgyis a kapu dönt).
        $count = fn (): int => Consent::query()
            ->where('channel', 'marketing')
            ->where('status', 'confirmed')
            ->whereIn('email', $this->audience->query($campaign)->reorder()->select('email'))
            ->count();

        $confirmed = $estimate === 0 ? 0 : Cache::remember(
            $this->targetingKey('campaign-consent', $campaign),
            self::CONSENT_CACHE_SECONDS,
            $budgeted ? fn (): ?int => $this->budgeted($count) : $count,
        );

        // A KERET-TÚLLÉPÉS nem gyorsítótárazódik (a `null`-t a `remember` nem tárolja), és nem blokkol.
        if ($confirmed === null) {
            return ['key' => 'consent', 'ok' => true, 'unavailable' => true, 'confirmed' => null, 'total' => $estimate];
        }

        $confirmed = (int) $confirmed;

        return [
            'key' => 'consent',
            'unavailable' => false,
            'ok' => $confirmed > 0,
            'confirmed' => $confirmed,
            'total' => $estimate,
        ];
    }

    private function estimate(Campaign $campaign): int
    {
        return $this->audience->estimate($campaign);
    }

    /**
     * A munka a LEKÉRDEZÉSI KERETBEN; túllépésnél `null` (a hívó „nem elérhető"-t mutat).
     *
     * @param  callable(): int  $work
     */
    private function budgeted(callable $work): ?int
    {
        try {
            return (int) $this->budget->run($work);
        } catch (QueryTooExpensive) {
            return null;
        }
    }

    /**
     * A SZERVER-ELLENŐRZÉSEK közül a bukók (domain, compliance, consent) — az ütemező az
     * indítás előtt ezt kérdezi (review RS1). A felületi szakaszok (pl. „most / később")
     * egy már ütemezett kampánynál nem értelmezettek, ezért itt nem számítanak.
     *
     * @return list<string>
     */
    public function failingChecks(Campaign $campaign): array
    {
        $campaign->loadMissing(['template', 'variants.template']);

        $checks = [
            $this->domainCheck($campaign),
            $this->complianceCheck($campaign),
            $this->consentCheck($campaign, $this->estimate($campaign)),
        ];

        return array_values(array_map(
            static fn (array $check): string => (string) $check['key'],
            array_filter($checks, static fn (array $check): bool => ! $check['ok']),
        ));
    }

    /** A célzás lenyomata a gyorsítótár-kulcshoz. */
    private function targetingKey(string $prefix, Campaign $campaign): string
    {
        return sprintf('%s:%d:%s', $prefix, (int) $campaign->workspace_id, sha1((string) json_encode([
            $this->uuids($campaign->target_lists),
            $this->uuids($campaign->target_segments),
            $this->uuids($campaign->exclude_lists),
            $this->uuids($campaign->exclude_segments),
        ])));
    }

    private function isVerified(string $email): bool
    {
        $host = DomainNormalizer::ofEmail($email);

        return $host !== '' && (bool) Domain::query()->where('name', $host)->first()?->isVerified();
    }

    /**
     * @param  mixed  $values
     * @return list<string>
     */
    private function uuids($values): array
    {
        return array_values(array_filter(array_map(
            static fn ($value) => is_scalar($value) ? (string) $value : null,
            (array) $values,
        )));
    }
}
