<?php

declare(strict_types=1);

use App\Modules\Inbound\Services\ArchiveInspector;
use App\Modules\Inbound\Services\InboundMimeParser;
use App\Modules\Inbound\Services\StrippedTextExtractor;

/*
 | A4 · 34., 36. kritérium — a PARSER korlátai és a stripped-text.
 |
 | Ez a kódbázis legveszélyesebb bemenete: a tartalmat ismeretlen harmadik fél írta. A
 | parser-sérülékenységek klasszikus osztályai mind abból élnek, hogy valamelyik dimenzió
 | korlátlan maradt — ezért mindegyiket KÜLÖN mérjük.
 */

beforeEach(function () {
    $this->parser = new InboundMimeParser;
});

it('a fejléceket és a törzset kiolvassa', function () {
    $raw = "From: Kovács Anna <anna@kuldo.example>\r\n"
        ."To: support@pelda.hu\r\n"
        .'Subject: =?UTF-8?B?'.base64_encode('Árvíztűrő kérdés')."?=\r\n"
        ."Message-ID: <abc123@kuldo.example>\r\n"
        ."\r\n"
        ."Szia, van egy kérdésem.\r\n";

    $parsed = $this->parser->parse($raw);

    expect($parsed->subject)->toBe('Árvíztűrő kérdés');
    expect($parsed->messageId)->toBe('abc123@kuldo.example');
    expect($parsed->headers['from'])->toContain('anna@kuldo.example');
    expect($parsed->bodyPlain)->toContain('van egy kérdésem');
});

it('az ISMÉTELT fejlécből az ELSŐT tartja meg', function () {
    // A levélkliensek is az elsőt mutatják. Ha a másodikat vennénk, a route-illesztés MÁST
    // látna, mint az olvasó — és a szabály a láthatatlan fejlécre illeszkedne.
    $raw = "Subject: Igazi\r\nSubject: Hamis\r\n\r\ntörzs";

    expect($this->parser->parse($raw)->subject)->toBe('Igazi');
});

it('a FOLYTATÓSOROS fejlécet összefűzi', function () {
    $raw = "Subject: Ez egy nagyon\r\n hosszú tárgy\r\n\r\ntörzs";

    expect($this->parser->parse($raw)->subject)->toBe('Ez egy nagyon hosszú tárgy');
});

it('a multipart alternative mindkét törzsét kiolvassa', function () {
    $raw = "Content-Type: multipart/alternative; boundary=\"HATAR\"\r\n\r\n"
        ."--HATAR\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nSima szöveg\r\n"
        ."--HATAR\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n<p>HTML szöveg</p>\r\n"
        ."--HATAR--\r\n";

    $parsed = $this->parser->parse($raw);

    expect($parsed->bodyPlain)->toContain('Sima szöveg');
    expect($parsed->bodyHtml)->toContain('HTML szöveg');
});

it('a base64 és a quoted-printable törzset dekódolja', function () {
    $raw = "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        .base64_encode('Árvíztűrő tükörfúrógép')."\r\n";

    expect($this->parser->parse($raw)->bodyPlain)->toContain('Árvíztűrő');

    $qp = "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
        ."K=C3=A9rd=C3=A9s\r\n";

    expect($this->parser->parse($qp)->bodyPlain)->toContain('Kérdés');
});

it('az ISO-8859-2 törzset UTF-8-ra alakítja', function () {
    // A magyar levelek jelentős része ma is latin-2 — konverzió nélkül a Postgres az EGÉSZ
    // sort visszautasítaná (érvénytelen UTF-8), és a levél elveszne.
    $latin2 = (string) mb_convert_encoding('Árvíztűrő', 'ISO-8859-2', 'UTF-8');

    $raw = "Content-Type: text/plain; charset=ISO-8859-2\r\n\r\n".$latin2;

    $body = $this->parser->parse($raw)->bodyPlain;

    expect(mb_check_encoding((string) $body, 'UTF-8'))->toBeTrue();
    expect($body)->toContain('Árvíztűrő');
});

it('a csatolmányt kiszedi, a fájlnevét megtisztítja', function () {
    // Az `../../etc/passwd` és a vezérlőkarakteres név egyaránt bevett trükk.
    $raw = "Content-Type: multipart/mixed; boundary=\"H\"\r\n\r\n"
        ."--H\r\nContent-Type: text/plain\r\n\r\nSzöveg\r\n"
        ."--H\r\nContent-Type: application/pdf; name=\"../../etc/passwd\"\r\n"
        ."Content-Disposition: attachment; filename=\"../../etc/passwd\"\r\n"
        ."Content-Transfer-Encoding: base64\r\n\r\n".base64_encode('PDF-tartalom')."\r\n"
        ."--H--\r\n";

    $parsed = $this->parser->parse($raw);

    expect($parsed->attachments)->toHaveCount(1);
    expect($parsed->attachments[0]->filename)->not->toContain('/');
    expect($parsed->attachments[0]->filename)->not->toStartWith('.');
    expect($parsed->attachments[0]->contents)->toBe('PDF-tartalom');
});

/*
 | A KORLÁTOK. Mindegyik külön dimenzió — egyik sem helyettesíti a másikat.
 */

it('a MÉLY beágyazás nem dönti el a parsert', function () {
    config(['inbound.parser.max_depth' => 5]);

    // 200 szintű matrjoska: rekurzív parsernél stack overflow, korlátlan iteratívnál percek.
    $raw = 'törzs';

    for ($i = 200; $i > 0; $i--) {
        $raw = "Content-Type: multipart/mixed; boundary=\"H{$i}\"\r\n\r\n--H{$i}\r\n".$raw."\r\n--H{$i}--\r\n";
    }

    $start = microtime(true);
    $parsed = $this->parser->parse($raw);
    $elapsed = microtime(true) - $start;

    expect($elapsed)->toBeLessThan(5.0);
    expect($parsed->warnings)->not->toBeEmpty();
});

it('a SOK RÉSZ sem viszi el', function () {
    config(['inbound.parser.max_parts' => 10]);

    $body = '';

    foreach (range(1, 2000) as $i) {
        $body .= "--H\r\nContent-Type: text/plain\r\n\r\nrész {$i}\r\n";
    }

    $raw = "Content-Type: multipart/mixed; boundary=\"H\"\r\n\r\n".$body."--H--\r\n";

    $parsed = $this->parser->parse($raw);

    expect($parsed->warnings)->not->toBeEmpty();
});

it('a MALFORMÁLT MIME nem dob kivételt', function (string $raw) {
    // A 36. kritérium: a szemetes levél nem hiba, hanem a valóság. Amit ki tudunk olvasni,
    // azt kiolvassuk; amit nem, arra figyelmeztetünk.
    $parsed = $this->parser->parse($raw);

    expect($parsed->warnings)->toBeArray();
})->with([
    'üres' => '',
    'csak fejléc' => 'Subject: x',
    'boundary nélküli multipart' => "Content-Type: multipart/mixed\r\n\r\ntörzs",
    'lezáratlan multipart' => "Content-Type: multipart/mixed; boundary=\"H\"\r\n\r\n--H\r\nContent-Type: text/plain\r\n\r\nx",
    'nyers bináris' => "\x00\x01\x02\xFF\xFE",
    'kettőspont nélküli fejléc' => "EzNemFejlec\r\n\r\ntörzs",
    'érvénytelen base64' => "Content-Transfer-Encoding: base64\r\n\r\n@@@@nem-base64@@@@",
]);

/*
 | STRIPPED-TEXT (34. kritérium) — magyar ÉS angol minták.
 */

it('levágja az idézett előzményt', function (string $body, string $vart) {
    expect((new StrippedTextExtractor)->extract($body))->toBe($vart);
})->with([
    'Gmail magyar' => [
        "Köszönöm, megnéztem.\n\n2026. szeptember 4. csütörtök Kovács Anna <anna@x.test> ezt írta:\n> Szia\n> Kérdés",
        'Köszönöm, megnéztem.',
    ],
    'Gmail angol' => [
        "Thanks, I checked.\n\nOn Thu, Sep 4, 2026 at 10:00 Anna <anna@x.test> wrote:\n> Hi\n> Question",
        'Thanks, I checked.',
    ],
    'Outlook magyar' => [
        "Rendben.\n\n-----Eredeti üzenet-----\nFeladó: Anna\nTárgy: Kérdés",
        'Rendben.',
    ],
    'Outlook angol' => [
        "Fine.\n\n-----Original Message-----\nFrom: Anna\nSubject: Question",
        'Fine.',
    ],
    'aláírás magyar' => [
        "Megoldottam.\n\nÜdvözlettel,\nKovács Anna\n+36 1 234 5678",
        'Megoldottam.',
    ],
    'aláírás angol' => [
        "Solved it.\n\nBest regards,\nAnna Kovacs\n+36 1 234 5678",
        'Solved it.',
    ],
    'RFC 3676 elválasztó' => [
        "Kész.\n\n-- \nKovács Anna\nventure.co",
        'Kész.',
    ],
    'mobil aláírás' => [
        "Ok.\n\nSent from my iPhone",
        'Ok.',
    ],
    'idézőjeles blokk' => [
        "Nem értem.\n\n> Az előző levél\n> második sora",
        'Nem értem.',
    ],
]);

it('ha a vágás mindent elvinne, a TELJES törzset adja vissza', function () {
    // Üres `stripped-text` rosszabb, mint egy hosszú: az előbbiből a support nem tud
    // dolgozni, az utóbbiból igen.
    $body = "> Csak idézett szöveg\n> semmi más";

    expect((new StrippedTextExtractor)->extract($body))->toBe($body);
});

it('az idézet NÉLKÜLI levelet érintetlenül hagyja', function () {
    $body = "Szia,\n\nvan egy kérdésem a számláról.\n\nKöszi";

    expect((new StrippedTextExtractor)->extract($body))->toBe($body);
});

/*
 | ZIP-BOMBA (35. kritérium, golden rule 9).
 */

it('a ZIP-BOMBÁT felismeri — kicsomagolás NÉLKÜL', function () {
    config(['inbound.attachments.max_compression_ratio' => 100]);

    // Kézzel összerakott ZIP helyi fejléc, ami 1 GB kicsomagolt méretet DEKLARÁL.
    $header = "PK\x03\x04".str_repeat("\x00", 18).pack('V', 1_073_741_824).str_repeat("\x00", 4);

    $inspection = (new ArchiveInspector)->inspect($header.str_repeat('x', 100));

    expect($inspection['bomb'])->toBeTrue();
    expect($inspection['reason'])->not->toBeNull();
});

it('a rendes ZIP átmegy', function () {
    $zip = (string) tempnam(sys_get_temp_dir(), 'zip');
    $archive = new ZipArchive;
    $archive->open($zip, ZipArchive::OVERWRITE);
    $archive->addFromString('szamla.txt', str_repeat('adat ', 100));
    $archive->close();

    $contents = (string) file_get_contents($zip);
    unlink($zip);

    $inspection = (new ArchiveInspector)->inspect($contents);

    expect($inspection['bomb'])->toBeFalse();
});

it('a nem-archívum csatolmányt nem vizsgálja', function () {
    expect((new ArchiveInspector)->inspect('sima szöveg')['bomb'])->toBeFalse();
});

it('a NYERS UTF-8 fejlécet nem csonkolja meg', function () {
    // RFC 6532 / SMTPUTF8: a nyers UTF-8 fejléc legitim. Az `iconv_mime_decode` feltétel
    // nélkül futtatva viszont kidobja az ékezeteket — mérve: „hosszú" → „hossz". Feltétel
    // nélküli dekódolással MINDEN ékezetes magyar tárgysor megcsonkulna.
    $raw = "Subject: Árvíztűrő tükörfúrógép\r\n\r\ntörzs";

    expect($this->parser->parse($raw)->subject)->toBe('Árvíztűrő tükörfúrógép');
});

/*
 | A ZIP-BOMBA a KÖZPONTI KÖNYVTÁRBÓL derül ki, nem a helyi fejlécekből.
 |
 | A streaming zip-írók (minden „zip on the fly" szerver, a Java `ZipOutputStream`, a legtöbb
 | nyelvi standard könyvtár) a helyi fejlécbe NULLÁT írnak, és a valós méretet a data
 | descriptorba meg a központi könyvtárba teszik. A helyi fejlécekből dolgozó ellenőrzés
 | ezekre `deklarált = 0`-t lát — mérve: egy 48 kB-os, 50 MB-ra bomló archívum „rendben"-t
 | kapott. A kicsomagolók viszont MIND a központi könyvtárból dolgoznak.
 */

function bombaZip(int $bytes = 5_000_000): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'zip');

    $archive = new ZipArchive;
    $archive->open($path, ZipArchive::OVERWRITE);
    $archive->addFromString('bomba.txt', str_repeat('A', $bytes));
    $archive->close();

    $contents = (string) file_get_contents($path);

    unlink($path);

    return $contents;
}

it('felismeri a bombát a KÖZPONTI könyvtárból', function (string $eset) {
    // A küszöböt a teszt SZŰKÍTI: a viselkedést mérjük, nem a gigabájtos fixtúrát. A
    // valóságban a padló 8 MB, és a bomba tízezerszeres arányú — a logika ugyanaz.
    config([
        'inbound.attachments.max_compression_ratio' => 100,
        'inbound.attachments.ratio_floor_bytes' => 1_000_000,
    ]);

    $bomba = bombaZip();

    $minta = match ($eset) {
        'sima' => $bomba,
        // Streaming: a helyi fejlécben CRC/méret = 0, a general purpose bit 3 be van állítva.
        'streaming' => substr_replace(substr_replace($bomba, chr(0x08), 6, 1), str_repeat("\0", 12), 14, 12),
        // SFX stub: a fájl NEM `PK`-val kezdődik.
        'prefixelt' => str_repeat("\0", 1000).$bomba,
        // Beágyazott, TÁROLT belső archívum.
        'beágyazott' => (function () use ($bomba) {
            $path = (string) tempnam(sys_get_temp_dir(), 'zip');
            $archive = new ZipArchive;
            $archive->open($path, ZipArchive::OVERWRITE);
            $archive->addFromString('belso.zip', $bomba);
            $archive->setCompressionName('belso.zip', ZipArchive::CM_STORE);
            $archive->close();
            $contents = (string) file_get_contents($path);
            unlink($path);

            return $contents;
        })(),
        // A gzip az utolsó négy bájtban tárolja a kicsomagolt méretet.
        'gzip' => (string) gzencode(str_repeat('A', 5_000_000), 9),
        default => $bomba,
    };

    $inspection = (new ArchiveInspector)->inspect($minta);

    expect($inspection['bomb'])->toBeTrue("a `{$eset}` eset átcsúszott");
})->with(['sima', 'streaming', 'prefixelt', 'beágyazott', 'gzip']);

it('a LEGITIM archívumokat átengedi', function () {
    // Egy `.docx` és egy `.jar` is zip: a téves riasztás itt valódi levelet blokkolna.
    $path = (string) tempnam(sys_get_temp_dir(), 'zip');
    $archive = new ZipArchive;
    $archive->open($path, ZipArchive::OVERWRITE);
    $archive->addFromString('szamla.txt', str_repeat('adat ', 200));
    $archive->close();
    $rendes = (string) file_get_contents($path);
    unlink($path);

    expect((new ArchiveInspector)->inspect($rendes)['bomb'])->toBeFalse();

    // Zipbe tett zip — a `.docx`/`.xlsx` pontosan ilyen.
    $path = (string) tempnam(sys_get_temp_dir(), 'zip');
    $archive = new ZipArchive;
    $archive->open($path, ZipArchive::OVERWRITE);
    $archive->addFromString('doc.docx', $rendes);
    $archive->setCompressionName('doc.docx', ZipArchive::CM_STORE);
    $archive->close();
    $agyazott = (string) file_get_contents($path);
    unlink($path);

    expect((new ArchiveInspector)->inspect($agyazott)['bomb'])->toBeFalse();
});

it('a nem ellenőrizhető formátumot NEM utasítja el, a vírusirtóra bízza', function () {
    // A bzip2/xz nem tárol kicsomagolt méretet. A tiltás itt csak annyit érne el, hogy a
    // legitim `.bz2` melléklet se működjön — a clamd viszont épp ilyen bemenetre készült,
    // és a szkennerünk fail-closed: amit nem tud átvizsgálni, az nem tölthető le.
    expect((new ArchiveInspector)->inspect('BZh91AY&SYakarmi')['bomb'])->toBeFalse();
});

it('a KIS méretre kibomló archívum nem bomba, akármilyen az aránya', function () {
    // Egy 100 000 üres, formázott sorból álló `.xlsx` néhány megabájtra bomlik ki, több száz
    // az arány — fizikailag ártalmatlan. Exportált táblázatok reálisan ütik ezt a küszöböt,
    // és a téves riasztás itt VALÓDI levelet blokkolna.
    config([
        'inbound.attachments.max_compression_ratio' => 150,
        'inbound.attachments.ratio_floor_bytes' => 8_388_608,
    ]);

    $kicsi = bombaZip(2_000_000);

    $inspection = (new ArchiveInspector)->inspect($kicsi);

    expect($inspection['uncompressed'])->toBeGreaterThan(1_000_000);
    expect($inspection['bomb'])->toBeFalse();
});

/*
 | A MEMÓRIA-PLAFON — a negyedik korlát.
 |
 | Az idő, a mélység és a részszám önmagában nem elég: a `memory_limit` túllépése FATAL
 | ERROR, ami NEM `Throwable`, tehát a job `catch` ága nem fogja el. A worker meghalna, az
 | üzenet pedig örökre `received` maradna — és egy ismeretlen feladó ezzel az ÖSSZES tenant
 | bejövő feldolgozását megállíthatná.
 */

it('a memória-plafon MEGSZAKÍTJA a dekódolást, nem hagyja elszállni', function () {
    // A KONFIGURÁLT keretet szűkítjük — ez a POLITIKA ága: van elég memória, csak mi magunk
    // döntöttünk úgy, hogy ennyit szánunk rá. A bemenet kicsi, hogy a mérés a soros
    // teszt-futásban is elférjen.
    config(['inbound.parser.max_memory_bytes' => 65_536]);

    $raw = "Content-Type: text/plain\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        .chunk_split(base64_encode(str_repeat('y', 200 * 1024)), 76, "\r\n");

    $parsed = (new InboundMimeParser)->parse($raw);

    // NEM dobott, és JELEZTE — a támogatás különben azt hinné, hogy a levél üres volt.
    expect($parsed->warnings)->not->toBeEmpty();
    expect(implode(' ', $parsed->warnings))->toContain('korlátba');
});

it('ha a PROCESSZBEN nincs elég hely, HANGOSAN elbukik', function () {
    // A csendes, üres parse rosszabb, mint a hangos hiba: az előbbi után senki nem tudná,
    // miért nincs a levélben semmi — és a hiba a KÖRNYEZETÉ, nem a levélé.
    $parser = new InboundMimeParser;

    $limit = (new ReflectionMethod($parser, 'processLimitBytes'))->invoke($parser);

    if ($limit === 0) {
        expect(true)->toBeTrue('korlátlan memory_limit — nincs mit mérni');

        return;
    }

    // Akkora „bemenet", amit ez a processz semmiképp nem tud kétszer eltartani.
    expect(fn () => (new ReflectionMethod($parser, 'ceiling'))->invoke($parser, $limit))
        ->toThrow(RuntimeException::class);
});

it('a plafon a PROCESSZ limitjéhez igazodik, nem csak a confighoz', function () {
    // Ha a konfigurált érték MAGASABB a `memory_limit`-nél, a saját plafonunk sosem sülne el
    // — és a FATAL ERROR visszahozná az egész problémát. A kettő közül a szigorúbb érvényes.
    config(['inbound.parser.max_memory_bytes' => 8 * 1024 * 1024 * 1024]);

    $parser = new InboundMimeParser;

    $ceiling = (new ReflectionMethod($parser, 'ceiling'))->invoke($parser, 1024);
    $limit = (new ReflectionMethod($parser, 'processLimitBytes'))->invoke($parser);

    if ($limit === 0) {
        expect(true)->toBeTrue('korlátlan memory_limit — nincs mit mérni');

        return;
    }

    expect($ceiling)->toBeLessThan($limit);
});
