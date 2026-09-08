<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/*
 | ŐRSZEM A BELÉPÉSI PONTOKRA.
 |
 | Ez a teszt egy konkrét, ötször megismételt hibából származik. A leiratkozó fejléc
 | („a fejléc a MIÉNK") szabályát HÁROM belépési pontnak kell betartania — `/v1/messages`,
 | `/v1/messages.mime` és az SMTP relay —, és korábban KETTŐN volt zár, EGYMÁSTÓL
 | FÜGGETLENÜL megírva. A harmadik csendben nyitva maradt: idegen tenant tokenje kiment a
 | levélben, egyetlen kapu sem látta.
 |
 | A tanulság nem az volt, hogy „elfelejtettük a relayt", hanem hogy három helyen három
 | külön implementáció élt ugyanarra a szabályra. Ez a teszt azt kényszeríti ki, hogy
 | MINDHÁROM úton UGYANAZ a fojtópont (`UnsubscribeHeaderPolicy`) döntsön — és ha valaki egy
 | negyedik belépési pontot ír, itt bukik el, nem a hatodik review-körben.
 */

it('mindhárom küldési belépési pont a KÖZÖS leiratkozó-fojtóponton dönt', function () {
    // A `MailgunPayload` a strukturált út bemenete, a `MimeParser` a két nyers úté (REST +
    // SMTP relay), a `SubmissionIngest` és a `SendMimeController` pedig a parser jelzésére
    // reagál. Mind az öt fájlnak látnia kell a szabályt.
    $required = [
        // A szabályt maga a policy mondja ki.
        'app/Modules/Messages/Data/MailgunPayload.php' => 'UnsubscribeHeaderPolicy::isManaged',
        'app/Modules/Messages/Services/MimeParser.php' => 'UnsubscribeHeaderPolicy::isManaged',
        'app/Modules/Sending/Models/Message.php' => 'UnsubscribeHeaderPolicy::isManaged',
        // A parser jelzését KÖTELEZŐ elolvasni — enélkül a fejléc csendben kimegy.
        'app/Modules/Messages/Http/Controllers/SendMimeController.php' => 'unsubscribe_headers',
        'app/Modules/Smtp/Services/SubmissionIngest.php' => 'unsubscribe_headers',
    ];

    $missing = [];

    foreach ($required as $path => $needle) {
        $source = (string) file_get_contents(base_path($path));

        if (! str_contains($source, $needle)) {
            $missing[] = $path.' (hiányzik: '.$needle.')';
        }
    }

    expect($missing)->toBeEmpty(
        'a leiratkozó-fojtópont nincs bekötve: '.implode(', ', $missing)
    );
});

it('a `MimeParser` MINDEN hívója olvassa az `unsubscribe_headers` jelzést', function () {
    // A parser külön jelenti a leiratkozó fejléceket — nem eldobja, nem átveszi. Aki a
    // parsert hívja, de a jelzést nem olvassa, az CSENDBEN átengedi az ügyfél fejlécét: a
    // modell (és így minden kapu) elől eltűnik, a kimenő bájtokkal viszont kimegy.
    $callers = [];

    foreach (File::allFiles(app_path('Modules')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        // Maga a parser nem hívó. És a `parseHeaders` NÉV nem elég azonosító: a
        // `RawMimeDkimSigner`-nek saját, privát `parseHeaders()`-e van a DKIM-kanonikalizáláshoz
        // — az nem ezt a szolgáltatást hívja. A hívó jele az, hogy a `MimeParser` TÍPUST is
        // ismeri.
        if (str_contains($file->getPathname(), 'MimeParser.php')
            || ! str_contains($source, 'Messages\Services\MimeParser')) {
            continue;
        }

        if (str_contains($source, 'parseHeaders(') && ! str_contains($source, 'unsubscribe_headers')) {
            $callers[] = $file->getRelativePathname();
        }
    }

    expect($callers)->toBeEmpty(
        'a `MimeParser::parseHeaders()` hívója nem kezeli a leiratkozó fejlécet: '
        .implode(', ', $callers)
    );
});
