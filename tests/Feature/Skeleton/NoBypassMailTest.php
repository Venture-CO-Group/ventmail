<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/*
 | Golden rule 2: a PreSendGate megkerülhetetlen. A stock Laravel mail-stack (smtp/ses/
 | postmark/resend) készen álló megkerülési útvonal — egy Mail::to(...)->send() a gate,
 | a domain-hitelesítés és az audit mellett menne ki. Ezért a transportokat kivesszük.
 */

it('csak megkerülhetetlen mailer-transportok maradnak', function () {
    $mailers = array_keys(config('mail.mailers', []));

    expect($mailers)->toEqualCanonicalizing(['log', 'array', 'failover']);
});

it('nincs harmadik felű e-mail szolgáltató konfigurálva', function () {
    $services = array_keys(config('services', []));

    foreach (['postmark', 'resend', 'ses', 'mailgun', 'sendgrid'] as $forbidden) {
        expect($services)->not->toContain($forbidden);
    }
});

it('a Sending modulon kívül senki nem használja a Mail facade-ot', function () {
    $offenders = [];

    foreach (File::allFiles(app_path()) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = $file->getRelativePathname();

        // A Sending modul a kimenő út gazdája; az őr-provider maga a kikényszerítés helye.
        if (str_starts_with($relative, 'Modules/Sending/')
            || $relative === 'Providers/OutboundMailGuardServiceProvider.php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        if (preg_match('/Illuminate\\\\Support\\\\Facades\\\\Mail|\bMail::(to|send|raw|queue)\(/', $contents)) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBeEmpty(
        'a Mail facade csak a Sending modulban használható (PreSendGate): '.implode(', ', $offenders)
    );
});
