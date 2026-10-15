<?php

declare(strict_types=1);

namespace App\Modules\Consent\Services;

use App\Modules\Consent\Jobs\SendConsentProof;
use App\Modules\Consent\Models\Consent;
use App\Modules\Consent\Models\ConsentEvent;
use App\Modules\Sending\Services\DomainNormalizer;
use App\Modules\Tenancy\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A consent-állapotváltások bizonyítéka a ventCookie főkönyv felé (FO3, C3 szerződés).
 *
 * A `ConsentRecorder` (a consent egyetlen írási pontja) hívja a helyi írás után; az egykattintásos
 * leiratkozás is (ott a helyi consent nem változik, a leiratkozás TÉNYE viszont bizonyíték).
 *
 *  - NYERS cím, IP, user-agent SOHA: az alany-hivatkozás `HMAC-SHA256(kisbetűs, vágott cím,
 *    CONSENT_LEDGER_SUBJECT_KEY)`; a bizonyíték zárt mezőkészletű (`method`, időbélyegek,
 *    szövegverzió, átlátszatlan `source_ref`, `legal_basis`).
 *  - A kiküldés a helyi tranzakció commitja UTÁN, sorba állítva megy (retry + backoff); a hiba
 *    (akár szinkron queue mellett is) elnyelődik és naplózódik — a helyi folyamatot sosem blokkolja.
 *  - Hiányos konfigurációnál nem küld (naplózza), a helyi consent hivatalos marad.
 */
final class ConsentLedgerReporter
{
    private const PURPOSE = 'email_marketing';

    public function requested(Consent $consent, string $eventUuid, ?Carbon $occurredAt = null): void
    {
        $at = $occurredAt ?? now();

        $this->report($consent, 'opt_in_requested', 'double_opt_in', $eventUuid, $at, ['requested_at' => $at->toIso8601String()]);
    }

    public function confirmed(Consent $consent, string $eventUuid, ?Carbon $requestedAt = null): void
    {
        $at = now();

        $this->report($consent, 'opt_in_confirmed', 'double_opt_in', $eventUuid, $at, array_filter([
            'requested_at' => $requestedAt?->toIso8601String(),
            'confirmed_at' => ($consent->confirmed_at ?? $at)->toIso8601String(),
        ]));
    }

    /**
     * Visszavonás / leiratkozás. A `$source` a helyi consent-forrás (`preference_center`,
     * `unsubscribe_link`, egyéb → `api`).
     */
    public function withdrawn(Consent $consent, ?string $source, string $discriminator): void
    {
        if (! $this->worthReporting($consent)) {
            return;
        }

        $method = match ($source) {
            'preference_center' => 'preference_center',
            'unsubscribe_link' => 'unsubscribe_link',
            default => 'api',
        };

        $this->report($consent, 'withdrawn', $method, $discriminator, now(), []);
    }

    /**
     * Az egykattintásos leiratkozás: a címhez tartozó marketing-consent (ha van) visszavonásának
     * bizonyítéka. A helyi consent-sort SZÁNDÉKOSAN nem írja át (a suppression a hivatalos
     * tiltás); a `$discriminator` (üzenet) miatt a levélkliens újrapróbálása azonos kulcsot kap.
     */
    public function withdrawnByEmail(string $email, string $discriminator): void
    {
        try {
            $consent = Consent::query()
                ->where('email', DomainNormalizer::listKey(trim($email)))
                ->where('channel', 'marketing')
                ->first();
        } catch (Throwable $e) {
            Log::warning('consent.ledger.lookup_failed', ['error' => $e::class]);

            return;
        }

        if ($consent !== null) {
            // ÁLLAPOT-VERZIÓ a kulcsban: withdraw → re-optin → withdraw ugyanabból a régi
            // levélből új kulcsot kap (különben a második visszavonás az elsőre replay-ződne).
            // A levélkliens ÚJRAPRÓBÁLÁSA (nincs állapotváltozás) azonos kulcsot ad.
            $version = (int) ConsentEvent::query()->where('consent_id', $consent->getKey())->max('id')
                .'@'.($consent->confirmed_at?->getTimestamp() ?? 0);

            $this->withdrawn($consent, 'unsubscribe_link', $discriminator.'|'.$version);
        }
    }

    /**
     * `HMAC-SHA256("<workspace uuid>|<kisbetűs, vágott cím>", CONSENT_LEDGER_SUBJECT_KEY)`.
     *
     * A WORKSPACE-hez kötött: ugyanaz a cím két tenantnál két külön alany — a főkönyvben a
     * tenantok között nem kapcsolható össze (tenant-izoláció). Kulcs-rotáció következménye:
     * a rotáció után képzett hivatkozások nem egyeznek a régiekkel (lásd a terv FO-döntését).
     */
    public function subjectRef(string $email, string $workspaceUuid): string
    {
        return hash_hmac('sha256', $workspaceUuid.'|'.mb_strtolower(trim($email)), (string) config('consent.ledger.subject_key'));
    }

    /**
     * Csak olyan visszavonásnak van értelme a főkönyvben, amelynek van előzménye: megerősített
     * hozzájárulás, vagy a ventMail által indított (nem importált) kérés.
     */
    private function worthReporting(Consent $consent): bool
    {
        return $consent->confirmed_at !== null || $consent->source !== 'import';
    }

    /** @param array<string, string> $times */
    private function report(Consent $consent, string $action, string $method, string $discriminator, Carbon $at, array $times): void
    {
        // SEMMI nem gördítheti vissza a hívó (consent-író) tranzakcióját: a teljes törzs védett.
        try {
            $this->build($consent, $action, $method, $discriminator, $at, $times);
        } catch (Throwable $e) {
            Log::warning('consent.ledger.report_failed', ['action' => $action, 'error' => $e::class]);
        }
    }

    /** @param array<string, string> $times */
    private function build(Consent $consent, string $action, string $method, string $discriminator, Carbon $at, array $times): void
    {
        // GDPR-törölt (üres) cím: nincs alany, nincs bizonyíték (N4).
        if (trim((string) $consent->email) === '') {
            return;
        }

        // A ledger a MARKETING hozzájárulás bizonyítéka (purpose = email_marketing).
        if ($consent->channel !== 'marketing') {
            return;
        }

        $config = (array) config('consent.ledger');

        foreach (['subject_key', 'site_id'] as $required) {
            if ((string) ($config[$required] ?? '') === '') {
                Log::notice('consent.ledger.not_configured', ['missing' => $required]);

                return;
            }
        }

        if (($config['driver'] ?? 'fake') === 'ventcookie') {
            foreach (['url', 'token'] as $required) {
                if ((string) ($config[$required] ?? '') === '') {
                    Log::notice('consent.ledger.not_configured', ['missing' => $required]);

                    return;
                }
            }
        }

        // A consent SAJÁT workspace-éből (nem a pillanatnyi kontextusból).
        $workspaceUuid = (string) Workspace::query()->whereKey($consent->workspace_id)->value('uuid');

        if ($workspaceUuid === '') {
            return;
        }

        $body = [
            'site_id' => (string) $config['site_id'],
            'subject_ref' => $this->subjectRef((string) $consent->email, $workspaceUuid),
            'scope_ref' => 'ws:'.$workspaceUuid.'/channel:marketing',
            'purpose' => self::PURPOSE,
            'action' => $action,
            'occurred_at' => $at->toIso8601String(),
            'evidence' => array_filter([
                'method' => $method,
                ...$times,
                // CSAK zárt alak (verzió-azonosító): szabad szöveg / cím nem mehet ki.
                'consent_text_version' => preg_match('/^[A-Za-z0-9._:-]{1,40}$/', (string) $consent->policy_version) === 1 ? (string) $consent->policy_version : null,
                // Átlátszatlan hivatkozás a helyi rekordra — személyes adat nélkül.
                'source_ref' => 'consent:'.$consent->uuid,
                'legal_basis' => 'consent',
            ], fn (mixed $value) => $value !== null),
        ];

        $key = 'vm-consent-'.hash('sha256', $consent->uuid.'|'.$action.'|'.$discriminator);
        $workspaceId = (int) $consent->workspace_id;
        $consentId = (int) $consent->getKey();
        $occurredAt = $at->toIso8601String();

        // A helyi tranzakció commitja UTÁN; a callback saját try/catch-e miatt a (szinkron
        // queue-nál a commitkor lefutó) job hibája sem szivárog a helyi folyamatba.
        DB::afterCommit(function () use ($workspaceId, $consentId, $body, $key, $occurredAt): void {
            try {
                SendConsentProof::dispatch($workspaceId, $consentId, $body, $key, $occurredAt);
            } catch (Throwable $e) {
                Log::warning('consent.ledger.dispatch_failed', ['error' => $e::class]);
            }
        });
    }
}
