<?php

declare(strict_types=1);

namespace App\Modules\Audience\Services\Import;

use App\Modules\Audience\Enums\ImportLegalBasis;
use App\Modules\Audience\Jobs\IndexImportedContacts;
use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Models\ContactField;
use App\Modules\Audience\Models\ContactImport;
use App\Modules\Audience\Models\ContactList;
use App\Modules\Audience\Models\ContactTag;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Audit\Services\KeyedHash;
use App\Modules\Billing\Enums\UsageDimension;
use App\Modules\Billing\Services\Metering;
use App\Modules\Consent\Models\Consent;
use App\Modules\Consent\Services\ConsentRecorder;
use App\Modules\Logs\Models\ErasedRecipient;
use App\Modules\Sending\Services\DomainNormalizer;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Modules\Validation\Services\EmailSyntax;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A KONTAKT-IMPORT MOTORJA (v1.1): ugyanaz a kód futtatja az ELŐNÉZETET (`commit = false`,
 * semmit nem ír) és a VALÓDI futást (`commit = true`) — így az előnézet számai nem térhetnek
 * el attól, ami a futásban ténylegesen történik.
 *
 * ## Teljesítmény: streamelve, chunkolva
 *
 * A sorokat egy generátor adja (O(1) memória), `chunk` soronként (alapból 1000) egyszerre
 * dolgozzuk fel: a chunk lekérdezései (tiltólista, létező kontakt, GDPR-sírkő, visszavont
 * consent, fájlon belüli duplikátum) mind `whereIn`-nel, címenként NULLA külön lekérdezéssel.
 * Az írás kötegelt `INSERT`/`UPSERT`, chunkonként egy tranzakcióban.
 *
 * ## Miért nem a modell-események
 *
 * A kötegelt írás megkerüli a `Contact` modell eseményeit — szándékosan. A soronkénti
 * `Auditable` audit-sor 300 000 törölhetetlen audit-rekordot írna a legkevésbé törölhető
 * táblába, a soronkénti `SyncContactToIndex` pedig 300 000 külön jobot. A helyükbe lép:
 *  - köteg-szintű audit (`contact_imports` + `audience.import.started/completed`),
 *  - kontakt-szintű bizonyíték (`consent_events` `imported` sor, jogalappal és forrással),
 *  - chunkonkénti `IndexImportedContacts` job a keresőindexhez.
 *
 * ## Amit az import SOHA nem tesz
 *
 *  - tiltólistás (globális vagy workspace), visszavont-consentű, GDPR-törölt címet nem ír és
 *    nem élesztet fel;
 *  - létező kontakt STÁTUSZÁT nem változtatja (leiratkozott marad leiratkozott);
 *  - megerősített consentet nem hoz létre (lásd `ConsentRecorder::importedBatch`);
 *  - levelet nem küld: nincs DOI-levél, nincs értesítés. // TODO ventMail: a re-permission
 *    (DOI) kampányt a tenant indítja, a PreSendGate-en át — az import maga nem küld.
 */
final class ContactImportProcessor
{
    /** Ennyi chunkonként frissítjük a tábla-statisztikát (20 × 1000 sor). */
    private const ANALYZE_EVERY = 20;

    public function __construct(
        private readonly EmailSyntax $syntax,
        private readonly ConsentRecorder $consents,
        private readonly ImportUploads $uploads,
        private readonly Metering $metering,
        private readonly AuditLogger $audit,
        private readonly AttributeCoercer $coercer,
        private readonly WorkspaceContext $context,
        private readonly ImportTableStats $tableStats,
    ) {}

    /**
     * Az előnézet vagy a futás lefuttatása. A hívó (a job) állította be a workspace-
     * kontextust, és ő viszi át a köteget a megfelelő `previewing`/`queued` állapotból.
     */
    public function run(ContactImport $import, bool $commit): void
    {
        $locale = $import->user?->locale;
        $previousLocale = app()->getLocale();

        if (is_string($locale) && in_array($locale, (array) config('app.supported_locales'), true)) {
            app()->setLocale($locale);
        }

        $run = null;

        try {
            $run = $this->plan($import, $commit);

            $this->tableStats->refresh();

            if ($commit) {
                $run->report = new ImportErrorReport($this->uploads->absolute($this->uploads->reportPath($import)));
                $run->list = $import->contact_list_id === null ? null : ContactList::query()->find($import->contact_list_id);
                $run->tags = array_map(
                    static fn (string $name): ContactTag => ContactTag::query()->firstOrCreate(['name' => $name]),
                    array_values(array_filter((array) $import->tags, 'is_string')),
                );
            }

            $import->forceFill([
                'status' => $commit ? ContactImport::STATUS_PROCESSING : ContactImport::STATUS_PREVIEWING,
                'phase' => 'inserting',
                'processed_rows' => 0,
                'new_count' => 0, 'updated_count' => 0, 'skipped_count' => 0, 'error_count' => 0,
                'failure_reason' => null,
                'started_at' => $commit ? now() : $import->started_at,
            ])->saveQuietly();

            $this->stream($run);

            $this->finish($run);
        } catch (\Throwable $e) {
            $this->fail($import, $run, $e);

            throw $e;
        } finally {
            app()->setLocale($previousLocale);
        }
    }

    private function stream(ImportRun $run): void
    {
        $hasHeader = (bool) ($run->import->profile['hasHeader'] ?? true);
        $chunkSize = max(1, (int) config('audience.import.chunk'));
        $chunk = [];
        $first = true;

        foreach ($this->uploads->reader($run->import)->rows() as $line => $cells) {
            if ($first) {
                $first = false;

                if ($hasHeader) {
                    continue;
                }
            }

            $chunk[] = [
                'line' => $line,
                'email' => (string) ($cells[$run->emailColumn] ?? ''),
                'first' => $run->firstNameColumn === null ? '' : (string) ($cells[$run->firstNameColumn] ?? ''),
                'last' => $run->lastNameColumn === null ? '' : (string) ($cells[$run->lastNameColumn] ?? ''),
                'attributes' => array_map(static fn (int $column): string => (string) ($cells[$column] ?? ''), $run->attributeColumns),
            ];

            if (count($chunk) >= $chunkSize) {
                $this->processChunk($run, $chunk);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            $this->processChunk($run, $chunk);
        }
    }

    /** @param list<array{line: int, email: string, first: string, last: string, attributes: array<string, string>}> $rows */
    private function processChunk(ImportRun $run, array $rows): void
    {
        $candidates = [];

        foreach ($rows as $row) {
            $run->processed++;

            $raw = trim($row['email']);

            if ($raw === '') {
                $this->reject($run, $row, ImportRowVerdict::MISSING_EMAIL);

                continue;
            }

            $email = DomainNormalizer::listKey($raw);

            if ($this->syntax->reason($email) !== null) {
                $this->reject($run, $row, ImportRowVerdict::INVALID_EMAIL);

                continue;
            }

            $attributes = [];

            foreach ($row['attributes'] as $key => $value) {
                $value = trim($value);

                if ($value === '') {
                    continue;
                }

                $typed = $this->coercer->coerce($run->types[$key] ?? 'text', $value);

                if ($typed === null) {
                    $this->reject($run, $row, ImportRowVerdict::INVALID_FIELD, ['field' => $key]);

                    continue 2;
                }

                $attributes[$key] = $typed;
            }

            $hash = KeyedHash::of('email', $email);

            // FÁJLON BELÜLI duplikátum: az első előfordulás marad, a későbbi kihagyva. A kulcs a
            // hash első 12 bájtja (96 bit): az ütközés esélye 10^6 sornál ~10^-17, a memória
            // soronként ~80 bájt — a `max_rows` plafon (alapból 1 M) ~80 MB-nál húzza meg a határt.
            $key = (string) hex2bin(substr($hash, 0, 24));

            if (isset($run->seen[$key])) {
                $this->reject($run, $row, ImportRowVerdict::DUPLICATE, ['line' => $run->seen[$key]]);

                continue;
            }

            $run->seen[$key] = $row['line'];

            $candidates[$hash] = [
                'line' => $row['line'],
                'email' => $email,
                'original' => $raw,
                'hash' => $hash,
                'first' => $this->name($row['first']),
                'last' => $this->name($row['last']),
                'attributes' => $attributes,
            ];
        }

        if ($candidates !== []) {
            $this->classifyAndWrite($run, $candidates);
        }

        $run->chunks++;

        // A TERVEZŐ statisztikája az import közben elavul (lásd `ImportTableStats`).
        if ($run->chunks % self::ANALYZE_EVERY === 0) {
            $this->tableStats->refresh();
        }

        $this->progress($run);
    }

    /** @param array<string, array<string, mixed>> $candidates */
    private function classifyAndWrite(ImportRun $run, array $candidates): void
    {
        $emails = array_column($candidates, 'email');

        // TILTÓLISTA: a modell global scope-ja a workspace-sorokat ÉS a platform-sorokat is
        // hozza; a lejárt tiltás nem tilt.
        $suppressed = Suppression::query()
            ->whereIn('email', $emails)
            ->get(['email', 'workspace_id', 'expires_at'])
            ->filter(fn (Suppression $suppression): bool => $suppression->isActive())
            ->mapWithKeys(fn (Suppression $suppression): array => [
                (string) $suppression->email => $suppression->workspace_id === null
                    ? ImportRowVerdict::SUPPRESSION_GLOBAL
                    : ImportRowVerdict::SUPPRESSION_WORKSPACE,
            ]);

        // VISSZAVONT consent: aki visszavonta, azt az import nem „hozza vissza".
        $revoked = Consent::query()
            ->whereIn('email', $emails)
            ->where('channel', 'marketing')
            ->where('status', 'revoked')
            ->pluck('email')
            ->flip();

        // GDPR-sírkő: a törlési kérés erősebb minden importnál.
        $erased = ErasedRecipient::query()
            ->whereIn('recipient_hash', array_map(static fn (string $email): string => ErasedRecipient::hash($email), $emails))
            ->pluck('recipient_hash')
            ->flip();

        // LÉTEZŐ kontakt — a törölteket (soft delete) is, mert az egyedi kulcs rájuk is vonatkozik.
        $existing = Contact::query()
            ->withTrashed()
            ->whereIn('email', $emails)
            ->get(['id', 'email', 'status', 'deleted_at'])
            ->keyBy('email');

        $roles = (array) config('validation.role_addresses');
        $disposable = (array) config('validation.disposable_domains');

        $new = [];
        $update = [];

        foreach ($candidates as $candidate) {
            $row = $this->asRow($candidate);
            $email = $candidate['email'];
            $at = strrpos($email, '@');
            $local = substr($email, 0, (int) $at);
            $domain = substr($email, (int) $at + 1);
            $current = $existing->get($email);

            $verdict = match (true) {
                $suppressed->has($email) => $suppressed->get($email),
                isset($erased[ErasedRecipient::hash($email)]) => ImportRowVerdict::ERASED,
                isset($revoked[$email]) => ImportRowVerdict::REVOKED_CONSENT,
                $current?->trashed() === true => ImportRowVerdict::DELETED_CONTACT,
                $run->skipRoles && in_array($local, $roles, true) => ImportRowVerdict::ROLE_ADDRESS,
                $run->skipDisposable && in_array($domain, $disposable, true) => ImportRowVerdict::DISPOSABLE_DOMAIN,
                default => null,
            };

            if ($verdict !== null) {
                $this->reject($run, $row, $verdict);

                continue;
            }

            if ($current !== null) {
                // A „létező" számláló az előnézet külön kártyája: akkor is számít, ha a
                // frissítés be van kapcsolva.
                $run->buckets['existing']++;

                if ($run->updateMode === 'skip') {
                    $this->reject($run, $row, ImportRowVerdict::EXISTING, countBucket: false);

                    continue;
                }

                $update[] = $candidate + ['existing_status' => (string) $current->status];
                $run->updated++;

                continue;
            }

            $new[] = $candidate;
            $run->new++;
        }

        if ($run->commit && ($new !== [] || $update !== [])) {
            $this->write($run, $new, $update);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $new
     * @param  list<array<string, mixed>>  $update
     */
    private function write(ImportRun $run, array $new, array $update): void
    {
        $workspaceId = $this->context->workspaceId();
        $now = now();
        $stamp = $now->toDateTimeString();
        $subscribedAt = $run->status === 'subscribed' ? ($run->consentDate ?? $now)->toDateTimeString() : null;

        $make = static fn (array $candidate, string $status, ?string $subscribed): array => [
            'uuid' => (string) Str::uuid7(),
            'workspace_id' => $workspaceId,
            'email' => $candidate['email'],
            'email_hash' => $candidate['hash'],
            'first_name' => $candidate['first'],
            'last_name' => $candidate['last'],
            'attributes' => $candidate['attributes'] === [] ? '{}' : json_encode($candidate['attributes'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'status' => $status,
            'subscribed_at' => $subscribed,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ];

        $recipients = [];
        $touched = [];
        $uuids = [];

        DB::transaction(function () use ($run, $new, $update, $make, $subscribedAt, &$recipients, &$touched, &$uuids): void {
            if ($new !== []) {
                $rows = array_map(fn (array $candidate): array => $make($candidate, $run->status, $subscribedAt), $new);
                $inserted = Contact::query()->insertOrIgnore($rows);

                // Versenyhelyzet: közben létrejött címet az `ON CONFLICT DO NOTHING` kihagyott;
                // a számlálókat az igazsághoz igazítjuk (a sor nem „új", hanem „létező").
                $lost = count($new) - $inserted;

                if ($lost > 0) {
                    $run->new -= $lost;
                    $run->skipped += $lost;
                    $run->buckets['existing'] += $lost;
                }

                $uuids = array_column($rows, 'uuid');

                foreach ($new as $candidate) {
                    $recipients[] = ['email' => $candidate['email'], 'hash' => $candidate['hash'], 'status' => $run->status];
                    $touched[$candidate['email']] = $run->status;
                }
            }

            if ($update !== []) {
                Contact::query()->upsert(
                    array_map(fn (array $candidate): array => $make($candidate, 'pending', null), $update),
                    ['workspace_id', 'email'],
                    $this->updateColumns($run->updateMode),
                );

                foreach ($update as $candidate) {
                    $recipients[] = ['email' => $candidate['email'], 'hash' => $candidate['hash'], 'status' => $candidate['existing_status']];
                    $touched[$candidate['email']] = $candidate['existing_status'];
                }

                // A frissítettek uuid-ját az adatbázis tudja (a sor már létezett).
                $uuids = array_merge($uuids, Contact::query()->whereIn('email', array_column($update, 'email'))->pluck('uuid')->all());
            }

            // A kontakt-azonosítókra csak a lista-tagsághoz és a címkékhez van szükség.
            if ($run->list !== null || $run->tags !== []) {
                $this->attachToListAndTags(
                    $run,
                    Contact::query()->whereIn('email', array_keys($touched))->pluck('id', 'email')->all(),
                    $touched,
                );
            }

            $this->consents->importedBatch(
                $recipients,
                'marketing',
                ImportLegalBasis::from($run->legalBasis)->evidenceBasis(),
                $run->evidence,
                [
                    'import' => (string) $run->import->uuid,
                    'source' => $run->import->consent_source,
                    'consent_date' => $run->consentDate?->toDateString(),
                    'file' => $run->import->file_name,
                ],
            );
        });

        IndexImportedContacts::dispatch($workspaceId, $uuids);
    }

    /**
     * A frissítendő oszlopok: a státuszhoz, a címhez és az `external_id`-hoz NEM nyúlunk — a
     * leiratkozás és a ventOS-szinkron kulcsa az importnál erősebb.
     *
     * `fill`: a MEGLÉVŐ érték nyer, a hiányt pótoljuk. `overwrite`: a fájl nem üres értéke nyer
     * (az üres cella nem törli a meglévőt).
     *
     * @return array<string, Expression>
     */
    private function updateColumns(string $mode): array
    {
        $existingWins = $mode === 'fill';

        $name = static fn (string $column): Expression => new Expression($existingWins
            ? "COALESCE(NULLIF(\"contacts\".\"{$column}\", ''), \"excluded\".\"{$column}\")"
            : "COALESCE(NULLIF(\"excluded\".\"{$column}\", ''), \"contacts\".\"{$column}\")");

        return [
            'first_name' => $name('first_name'),
            'last_name' => $name('last_name'),
            'attributes' => new Expression($existingWins
                ? "\"excluded\".\"attributes\" || COALESCE(\"contacts\".\"attributes\", '{}'::jsonb)"
                : "COALESCE(\"contacts\".\"attributes\", '{}'::jsonb) || \"excluded\".\"attributes\""),
            'updated_at' => new Expression('"excluded"."updated_at"'),
        ];
    }

    /**
     * Lista-tagság és címkék — csak ÚJ tagságot írunk: a meglévő (akár leiratkozott) tagság
     * érintetlen marad, és a leiratkozott / kitisztított kontaktot nem tesszük listatagnak.
     *
     * @param  array<string, int>  $ids  cím → kontakt-id
     * @param  array<string, string>  $statuses  cím → kontakt-státusz az import után
     */
    private function attachToListAndTags(ImportRun $run, array $ids, array $statuses): void
    {
        if ($run->list !== null) {
            $members = [];

            foreach ($ids as $email => $id) {
                $status = $statuses[$email] ?? 'pending';

                if (in_array($status, ['subscribed', 'pending'], true)) {
                    $members[$id] = $status;
                }
            }

            $already = $run->list->contacts()->whereIn('contacts.id', array_keys($members))->pluck('contacts.id')->all();

            $attach = [];

            foreach (array_diff_key($members, array_flip($already)) as $id => $status) {
                $attach[$id] = [
                    'status' => $status,
                    'subscribed_at' => $status === 'subscribed' ? ($run->consentDate ?? now()) : null,
                ];
            }

            if ($attach !== []) {
                $run->list->contacts()->attach($attach);
            }
        }

        foreach ($run->tags as $tag) {
            $already = $tag->contacts()->whereIn('contacts.id', array_values($ids))->pluck('contacts.id')->all();
            $missing = array_values(array_diff(array_values($ids), $already));

            if ($missing !== []) {
                $tag->contacts()->attach($missing);
            }
        }
    }

    /**
     * Egy sor ELUTASÍTÁSA: számláló, minta (előnézet) vagy hibariport-sor (futás).
     *
     * @param  array{line: int, email: string, first: string, last: string, ...}  $row
     * @param  array<string, mixed>  $detail
     */
    private function reject(ImportRun $run, array $row, string $code, array $detail = [], bool $countBucket = true): void
    {
        if ($countBucket) {
            $run->buckets[ImportRowVerdict::BUCKETS[$code]]++;
        }

        ImportRowVerdict::isError($code) ? $run->errors++ : $run->skipped++;

        $name = trim($row['first'].' '.$row['last']);

        if ($run->report !== null) {
            $run->report->add(
                $row['line'],
                $row['email'],
                $name,
                ImportRowVerdict::isError($code) ? 'error' : 'skipped',
                $code,
                (string) __('ventmail.audience.import.reason.'.$code, $detail),
            );

            return;
        }

        if (
            count($run->samples) < ImportRun::MAX_SAMPLES
            && ($run->samplesPerCode[$code] ?? 0) < ImportRun::SAMPLES_PER_CODE
        ) {
            $run->samplesPerCode[$code] = ($run->samplesPerCode[$code] ?? 0) + 1;
            $run->samples[] = ['line' => $row['line'], 'email' => mb_substr($row['email'], 0, 254), 'name' => $name, 'code' => $code, 'detail' => $detail];
        }
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array{line: int, email: string, first: string, last: string}
     */
    private function asRow(array $candidate): array
    {
        return [
            'line' => (int) $candidate['line'],
            'email' => (string) ($candidate['original'] ?? $candidate['email']),
            'first' => (string) $candidate['first'],
            'last' => (string) $candidate['last'],
        ];
    }

    private function name(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, 255);
    }

    /** Haladás-frissítés: modell-esemény (és audit-sor) nélkül, chunkonként egyszer. */
    private function progress(ImportRun $run): void
    {
        $this->persistCounters($run);

        $stats = (array) $run->import->stats;
        $stats['memory_mb'] = (int) round(memory_get_peak_usage() / 1048576);
        $stats['elapsed'] = round(microtime(true) - $run->startedAt, 1);
        $stats['chunks'] = $run->chunks;
        $run->import->forceFill(['stats' => $stats])->saveQuietly();
    }

    private function persistCounters(ImportRun $run): void
    {
        $run->import->forceFill([
            'processed_rows' => $run->processed,
            'new_count' => $run->new,
            'updated_count' => $run->updated,
            'skipped_count' => $run->skipped,
            'error_count' => $run->errors,
        ])->saveQuietly();
    }

    private function finish(ImportRun $run): void
    {
        $import = $run->import;
        $this->persistCounters($run);

        $stats = array_merge((array) $import->stats, $run->buckets, [
            'memory_mb' => (int) round(memory_get_peak_usage() / 1048576),
            'elapsed' => round(microtime(true) - $run->startedAt, 1),
            'chunks' => $run->chunks,
            // Az előnézet mintája a felületé; a futás után a hibariport viszi a sorokat, és
            // a minta (valódi címek) nem marad az import-rekordban.
            'samples' => $run->commit ? [] : $run->samples,
        ]);

        if (! $run->commit) {
            $import->forceFill([
                'status' => ContactImport::STATUS_PREVIEWED,
                'phase' => null,
                'total_rows' => $run->processed,
                'stats' => $stats,
            ])->saveQuietly();

            return;
        }

        $reportPath = null;

        if ($run->report !== null) {
            $run->report->close();
            $reportPath = $run->report->count() > 0 ? $this->uploads->reportPath($import) : null;

            if ($reportPath === null) {
                $this->uploads->delete($this->uploads->reportPath($import));
            }
        }

        // A forrásfájl személyes adatok tömege: a feldolgozás után nincs rá szükség.
        $this->uploads->delete($import->storage_path);

        $profile = (array) $import->profile;
        unset($profile['sample']);

        $import->forceFill([
            'status' => ContactImport::STATUS_COMPLETED,
            'phase' => null,
            'total_rows' => $run->processed,
            'storage_path' => null,
            'profile' => $profile,
            'stats' => $stats,
            'error_report_path' => $reportPath,
            'completed_at' => now(),
        ])->saveQuietly();

        // contacts_peak: az import után a valós létszám — a periódus MAXIMUMÁT tartja.
        $this->metering->observePeak(UsageDimension::ContactsPeak, Contact::query()->count());

        $this->audit->log(
            'audience.import.completed',
            $import,
            context: [
                'import' => (string) $import->uuid,
                'new' => $run->new,
                'updated' => $run->updated,
                'skipped' => $run->skipped,
                'errors' => $run->errors,
                'legal_basis' => $import->legal_basis,
                'file_name' => $import->file_name,
            ],
            actor: $import->user,
        );
    }

    private function fail(ContactImport $import, ?ImportRun $run, \Throwable $e): void
    {
        $run?->report?->close();

        $reason = $e instanceof ImportLimitExceeded ? $e->reason : 'error';

        $import->forceFill([
            'status' => ContactImport::STATUS_FAILED,
            'phase' => null,
            // A kivétel üzenete SQL-értékeket (címeket) is hordozhat: a rekordba csak a kód
            // kerül, a részletek a laravel.log-ba.
            'failure_reason' => $reason,
            'completed_at' => now(),
        ])->saveQuietly();

        $this->audit->log(
            'audience.import.failed',
            $import,
            context: ['import' => (string) $import->uuid, 'reason' => $reason, 'file_name' => $import->file_name],
            actor: $import->user,
        );
    }

    /** A leképezésből és a beállításokból felépített futás-terv. */
    private function plan(ContactImport $import, bool $commit): ImportRun
    {
        $mapping = (array) $import->mapping;

        $email = null;
        $first = null;
        $last = null;
        $attributes = [];

        foreach ($mapping as $column => $target) {
            $column = (int) $column;

            match (true) {
                $target === 'email' => $email = $column,
                $target === 'first_name' => $first = $column,
                $target === 'last_name' => $last = $column,
                is_string($target) && str_starts_with($target, 'attr:') => $attributes[substr($target, 5)] = $column,
                default => null,
            };
        }

        if ($email === null) {
            throw new ImportLimitExceeded('mapping');
        }

        $types = ContactField::query()->pluck('type', 'key')->all();

        foreach (ColumnSuggester::BUILTIN_ATTRIBUTES as $builtin) {
            $types[$builtin] = 'text';
        }

        // Ismeretlen attribútum-kulcsot (törölt mező) nem írunk.
        $attributes = array_filter($attributes, static fn (string $key): bool => isset($types[$key]), ARRAY_FILTER_USE_KEY);

        $basis = ImportLegalBasis::tryFrom((string) $import->legal_basis) ?? ImportLegalBasis::Unknown;
        $date = $import->consent_date === null ? null : CarbonImmutable::parse($import->consent_date);

        return new ImportRun(
            import: $import,
            commit: $commit,
            emailColumn: $email,
            firstNameColumn: $first,
            lastNameColumn: $last,
            attributeColumns: $attributes,
            types: $types,
            status: $basis->resultingStatus($import->consent_source, $date),
            legalBasis: $basis->value,
            evidence: mb_substr(trim($import->consent_source.($date === null ? '' : ' ('.$date->toDateString().')')), 0, 1000),
            updateMode: (string) $import->update_mode,
            skipRoles: $import->role_policy !== 'import',
            skipDisposable: $import->disposable_policy !== 'import',
            consentDate: $date,
        );
    }
}
