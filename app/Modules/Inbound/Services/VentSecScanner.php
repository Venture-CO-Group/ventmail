<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Services;

use App\Modules\Inbound\Contracts\VirusScanner;
use App\Modules\Inbound\Data\ScanResult;
use App\Modules\VentSec\Services\ServiceTokenProvider;
use App\Modules\VentSec\Services\VerdictKeyStore;
use App\Modules\VentSec\Services\VerdictVerifier;
use App\Modules\VentSec\Support\SecureUrl;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use stdClass;
use Throwable;

/**
 * Csatolmány-szkennelés a ventSec szinkron Scan API-ján (`POST /scan/v1/files`, profil:
 * `mail-inbound`). FAIL-CLOSED: `clean`/`infected` csak akkor, ha az ítélet Ed25519-aláírása a
 * ventSec közzétett kulcsával érvényes ÉS pontosan erre a tartalomra szól (sha256, méret, ref,
 * profil). Minden más — timeout, 4xx/5xx, hiányzó token, rossz aláírás, ismeretlen ítélet —
 * `unavailable`, és a csatolmány nem tölthető le.
 *
 * A FÁJLNÉV soha nem hagyja el a ventMailt: csak a kiterjesztés megy át
 * (`X-VentSec-Declared-Ext`), a hivatkozás a tartalom hash-éből képzett, átlátszatlan `ref`.
 */
final class VentSecScanner implements VirusScanner
{
    public function __construct(
        private readonly ServiceTokenProvider $tokens,
        private readonly VerdictKeyStore $keys,
        private readonly VerdictVerifier $verifier,
    ) {}

    public function scan(string $filename, string $contents, ?string $reference = null): ScanResult
    {
        $base = rtrim((string) config('ventsec.scan_url'), '/');

        if ($base === '' || ! SecureUrl::ok($base)) {
            return ScanResult::unavailable('A ventSec Scan API nincs (biztonságosan) beállítva.');
        }

        if (strlen($contents) > (int) config('ventsec.scan_max_bytes')) {
            return ScanResult::unavailable('A csatolmány meghaladja a szinkron szkennelés plafonját.');
        }

        $token = $this->tokens->token();

        if ($token === null) {
            return ScanResult::unavailable('A ventSec m2m token nem érhető el.');
        }

        $sha256 = hash('sha256', $contents);
        $ref = 'ventmail:'.substr($sha256, 0, 32);

        $headers = [
            'X-VentSec-Profile' => (string) config('ventsec.scan_profile'),
            'X-VentSec-Ref' => $ref,
            // PÉLDÁNYONKÉNT egyedi: a tartalom-hash kulcsa az elavult ítéletet játszaná vissza
            // (és a kiterjesztés-váltás 422-t adna). Hivatkozás nélkül hívásonként új kulcs.
            'Idempotency-Key' => 'vm-scan-'.($reference !== null && preg_match('/^[\x21-\x7e]{1,100}$/', $reference) === 1 ? $reference : (string) Str::uuid()),
        ];

        // CSAK a kiterjesztés: a fájlnév személyes adat lehet (Számla_Kovács_Béla.pdf).
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (preg_match('/^[a-z0-9]{1,16}$/', $extension) === 1) {
            $headers['X-VentSec-Declared-Ext'] = $extension;
        }

        try {
            $response = Http::withToken($token)
                ->withHeaders($headers)
                ->withBody($contents, 'application/octet-stream')
                ->timeout((int) config('ventsec.scan_timeout'))->connectTimeout(5)
                ->withOptions(['allow_redirects' => false])
                ->post($base.'/scan/v1/files');
        } catch (Throwable $e) {
            Log::warning('ventsec.scan.request_failed', ['error' => $e::class]);

            return ScanResult::unavailable('A ventSec Scan API nem érhető el.');
        }

        if ($response->status() === 401) {
            $this->tokens->forget();
        }

        if ($response->status() !== 200) {
            Log::warning('ventsec.scan.http_status', ['status' => $response->status()]);

            return ScanResult::unavailable('A ventSec Scan API hibát adott: HTTP '.$response->status());
        }

        $verdict = json_decode($response->body(), false);

        if (! $verdict instanceof stdClass) {
            return ScanResult::unavailable('Értelmezhetetlen ventSec válasz.');
        }

        return $this->interpret($verdict, $sha256, strlen($contents), $ref);
    }

    private function interpret(stdClass $verdict, string $sha256, int $size, string $ref): ScanResult
    {
        $keyId = $verdict->key_id ?? null;
        $publicKey = is_string($keyId) ? $this->keys->find($keyId) : null;

        if ($publicKey === null) {
            return ScanResult::unavailable('Ismeretlen ventSec ítélet-kulcs.');
        }

        if (! $this->verifier->verify($verdict, $publicKey)) {
            return ScanResult::unavailable('Érvénytelen ventSec ítélet-aláírás.');
        }

        // Az aláírás érvényes, de EZ-E az ítélet erre a tartalomra? Egy másik fájl valódi,
        // aláírt `clean` ítéletének visszajátszása ellen.
        if (($verdict->sha256 ?? null) !== $sha256 || ($verdict->size ?? null) !== $size
            || ($verdict->ref ?? null) !== $ref || ($verdict->profile ?? null) !== config('ventsec.scan_profile')) {
            return ScanResult::unavailable('A ventSec ítélet nem erre a csatolmányra szól.');
        }

        // FRISSESSÉG: az aláírt `scanned_at` a határon belül legyen — egy régi, érvényes
        // aláírású ítélet visszajátszása (vagy rossz órájú ventSec) nem jó alap.
        if (! $this->fresh($verdict->scanned_at ?? null)) {
            return ScanResult::unavailable('A ventSec ítélet nem friss (scanned_at).');
        }

        $scanId = is_string($verdict->scan_id ?? null) ? $verdict->scan_id : null;

        // ELAVULT szignatúra-adatbázissal készült `clean` nem elég egy bejövő levélhez
        // (mail-inbound, fail-closed — FO döntés); a fertőzött ítélet így is fertőzött.
        if (($verdict->binary_verdict ?? null) === 'clean' && ($verdict->stale_signatures ?? false) !== false) {
            return ScanResult::unavailable('A ventSec szignatúrái elavultak — a tiszta ítélet nem fogadható el.', $scanId);
        }

        return match ($verdict->binary_verdict ?? null) {
            'clean' => ScanResult::clean($scanId),
            'infected' => ScanResult::infected($this->signatureName($verdict), $scanId),
            'unavailable' => ScanResult::unavailable('A ventSec nem tudta megvizsgálni a csatolmányt.', $scanId),
            default => ScanResult::unavailable('Ismeretlen ventSec ítélet.'),
        };
    }

    private function fresh(mixed $scannedAt): bool
    {
        if (! is_string($scannedAt)) {
            return false;
        }

        try {
            $at = Carbon::parse($scannedAt);
        } catch (Throwable) {
            return false;
        }

        $age = now()->getTimestamp() - $at->getTimestamp();

        // Legfeljebb 60 s óra-eltérés a jövő felé.
        return $age <= (int) config('ventsec.verdict_max_age') && $age >= -60;
    }

    private function signatureName(stdClass $verdict): string
    {
        foreach ((array) ($verdict->steps ?? []) as $step) {
            if ($step instanceof stdClass && ($step->result ?? null) === 'infected' && is_string($step->signature ?? null) && $step->signature !== '') {
                return mb_substr($step->signature, 0, 120);
            }
        }

        return 'infected';
    }
}
