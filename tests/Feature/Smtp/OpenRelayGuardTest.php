<?php

declare(strict_types=1);

use App\Modules\Smtp\Contracts\SubmissionSink;
use App\Modules\Smtp\Data\ChannelSecurity;
use App\Modules\Smtp\Data\SubmissionPrincipal;
use App\Modules\Smtp\Services\CredentialAuthenticator;
use App\Modules\Smtp\Transport\StreamSubmissionServer;
use Illuminate\Support\Facades\File;

/*
 | A2 · 12. kritérium — NYÍLT RELAY TILOS (blocking).
 |
 | NÉGY FÜGGETLEN teszt, szándékosan. Egyetlen refaktor se tudja mind a négyet eltüntetni:
 | egy elrontott `if` a protokoll-tesztet bukja, egy „gyorsító" közvetlen driver-hívás az
 | arch-tesztet, egy nullable-lá tett paraméter a reflection-tesztet. A negyedik (az
 | állapotgép-invariáns, ami a parancs-ábécé minden ≤4 hosszú sorozatát végigpróbálja) a
 | `SubmissionProtocolTest`-ben él, mert ott van az állapotgép.
 */

it('a küldés belépési pontja NEM MEGFOGALMAZHATÓ hitelesítés nélkül', function () {
    // Ez a legerősebb zár: nem futásidejű ellenőrzés, hanem TÍPUS. Nincs „ha null, akkor
    // default workspace" ág, mert nincs hova írni.
    $parameter = (new ReflectionMethod(SubmissionSink::class, 'accept'))->getParameters()[0];

    expect($parameter->getType()?->getName())->toBe(SubmissionPrincipal::class);
    expect($parameter->allowsNull())->toBeFalse('a SubmissionPrincipal nullable lett — a nyílt relay tilalma elveszett');
});

it('a SubmissionPrincipal csak hitelesítésből származhat', function () {
    // Ha bárhol máshol is példányosítható lenne, a típus-garancia semmit nem érne.
    $offenders = [];

    foreach (File::allFiles(app_path()) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = $file->getRelativePathname();

        // A hitelesítők állítják elő (valódi és fake), és a saját osztálya.
        $allowed = [
            'Modules/Smtp/Services/CredentialAuthenticator.php',
            'Modules/Smtp/Testing/FakeSmtpAuthenticator.php',
            'Modules/Smtp/Data/SubmissionPrincipal.php',
        ];

        if (in_array($relative, $allowed, true)) {
            continue;
        }

        if (str_contains((string) file_get_contents($file->getPathname()), 'new SubmissionPrincipal')) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBeEmpty('a SubmissionPrincipal hitelesítésen kívül is előállítható: '.implode(', ', $offenders));
});

it('az Smtp modul NEM ismeri a drivert és a dispatchert', function () {
    // A modul egyetlen kijárata a `SendMessage` action — tehát a PreSendGate megkerülése
    // nem „elfelejtett hívás" kérdése, hanem szerkezetileg lehetetlen (golden rule 2).
    $offenders = [];

    foreach (File::allFiles(app_path('Modules/Smtp')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        // KÓD, nem komment: a tiltás magyarázata pont ezeket a neveket említi. Ezért
        // tokenizálunk — a dokumentáció nem lehet a teszt buktatója.
        $code = '';

        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        foreach (['SendingDriver', 'MessageDispatcher'] as $forbidden) {
            if (str_contains($code, $forbidden)) {
                $offenders[] = $file->getRelativePathname().' → '.$forbidden;
            }
        }
    }

    expect($offenders)->toBeEmpty('az Smtp modul megkerülné a gate-et: '.implode(', ', $offenders));
});

it('az Smtp modul a SendMessage actionön megy ki', function () {
    // A fordítottja is kell: ha SENKI nem hívja a `SendMessage`-t, akkor a modul nem is
    // küld — és a fenti tiltás vákuumban lenne igaz.
    $callers = [];

    foreach (File::allFiles(app_path('Modules/Smtp')) as $file) {
        if ($file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), 'SendMessage')) {
            $callers[] = $file->getRelativePathname();
        }
    }

    expect($callers)->not->toBeEmpty('az Smtp modul sehol nem hívja a SendMessage actiont');
});

it('a listener a 25-ös portot nem kötheti', function () {
    // A port 25 a BEJÖVŐ (MX) oldalé: más parser, más bizalmi modell (A4). Egy elgépelt
    // `--port=25` némán MX-szerverré tenné a submission listenert.
    expect(config('smtp.allowed_ports'))->not->toContain(25);

    expect(fn () => app(StreamSubmissionServer::class)->serve('127.0.0.1', 25, false))
        ->toThrow(RuntimeException::class);
});

it('a submission mindig TLS mögött van — a require_tls oszlop MÁST jelent', function () {
    // Az `smtp_credentials.require_tls` a KIMENŐ lábra vonatkozik (`o:require-tls`).
    // Ha valaki azt hinné, hogy ezzel plaintext submission engedélyezhető, a hitelesítő
    // akkor is elutasítja a titkosítatlan csatornát.
    $authenticator = app(CredentialAuthenticator::class);

    expect($authenticator->authenticate('barmi', 'barmi', ChannelSecurity::plaintext()))
        ->toBeNull();
});
