<?php

declare(strict_types=1);

use App\Modules\Domains\Models\Domain;
use App\Modules\Sending\Services\RawMimeDkimSigner;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Mime\Crypto\DkimSigner as SymfonyDkimSigner;
use Symfony\Component\Mime\Email;
use Tests\Support\DkimVerifier;

uses(RefreshDatabase::class);

/*
 | KAT-vektorok a NYERS MIME DKIM-aláírására.
 |
 | A kanonikalizálás az a rész, ami pontosan ott hibázna, ahol nem látjuk: a fogadó
 | oldalán, ahol a levél már spam-be került. Ezért NEM elég a „lefut és ad valamit" teszt.
 | Három, egymástól független bizonyíték:
 |   1. RFC 6376 §3.4.5 szöveges vektor a fejléc- és törzs-kanonikalizálásra;
 |   2. EGYEZŐSÉG a Symfony (harmadik felű, bejáratott) aláírójával — bájtra azonos `b=`;
 |   3. független openssl-verifikáció + tamper-teszt.
 */

beforeEach(function () {
    app(WorkspaceContext::class)->set(Workspace::factory()->create());

    $this->domain = Domain::create([
        'name' => 'pelda.hu',
        'status' => 'verified',
        'verified_at' => now(),
        'dkim_selector' => 'vm202609',
        'dkim_private_key' => file_get_contents(base_path('tests/Fixtures/dkim-test-key.pem')),
        'dkim_public_key' => file_get_contents(base_path('tests/Fixtures/dkim-test-key.pub.pem')),
    ]);
});

/*
 | 1. RFC 6376 §3.4.5 — a szabvány saját példája.
 */

it('a relaxed kanonikalizálás megfelel az RFC 6376 §3.4.5 vektorának', function () {
    // A szabványban szereplő bemenet…
    $raw = "A: X\r\nB : Y\t\r\n\tZ  \r\n\r\n C \r\nD \t E\r\n\r\n\r\n";

    $signer = new RawMimeDkimSigner;

    $headers = (new ReflectionMethod($signer, 'parseHeaders'))
        ->invoke($signer, (new ReflectionMethod($signer, 'split'))->invoke($signer, $raw)[0]);

    $canonicalHeaders = '';

    foreach (['a', 'b'] as $name) {
        // A parser NÉVENKÉNT, előfordulási sorrendben tárol (RFC 6376 §5.4.2 miatt).
        $canonicalHeaders .= (new ReflectionMethod($signer, 'canonicalizeHeader'))
            ->invoke($signer, $name, $headers[$name][0]);
    }

    // …és a szabványban szereplő elvárt kimenet.
    expect($canonicalHeaders)->toBe("a:X\r\nb:Y Z\r\n");

    $body = (new ReflectionMethod($signer, 'split'))->invoke($signer, $raw)[1];

    expect((new ReflectionMethod($signer, 'canonicalizeBody'))->invoke($signer, $body))
        ->toBe(" C\r\nD E\r\n");
});

it('az ÜRES törzs kanonikus alakja üres', function () {
    // RFC 6376 §3.4.4: az üres törzsből nem lesz CRLF. Ha itt tévednénk, MINDEN üres törzsű
    // levél body-hash-e hibás lenne — és a fogadó mind elutasítaná.
    $signer = new RawMimeDkimSigner;

    expect((new ReflectionMethod($signer, 'canonicalizeBody'))->invoke($signer, ''))->toBe('');
    expect((new ReflectionMethod($signer, 'canonicalizeBody'))->invoke($signer, "\r\n\r\n"))->toBe('');
});

/*
 | 2. Egyezőség a Symfony aláírójával — ugyanaz a levél, ugyanaz az aláírás.
 */

it('a saját kanonikalizálásunk ELFOGADJA a Symfony aláírását', function () {
    // KERESZT-VERIFIKÁCIÓ: a bejáratott, harmadik felű aláíró levelét a MI
    // kanonikalizálásunkkal ellenőrizzük. Ha bárhol eltérnénk a szabványtól (fejléc-
    // unfolding, whitespace-normalizálás, törzs-lezárás), ez a teszt elbukna — és pont ez
    // az a hiba, ami különben csak a fogadónál derülne ki, a spam-mappában.
    $email = (new Email)
        ->from('no-reply@pelda.hu')
        ->to('cimzett@example.test')
        ->subject('Számla   —   márciusi')
        ->text("Kedves Ügyfél,\r\n\r\nköszönjük.\t\r\n");

    $email->getHeaders()->addIdHeader('Message-ID', 'azonosito@pelda.hu');
    $email->getHeaders()->addDateHeader('Date', (new DateTimeImmutable)->setTimestamp(1_756_800_000));

    $symfonySigned = (new SymfonyDkimSigner(
        (string) $this->domain->dkim_private_key,
        'pelda.hu',
        'vm202609'
    ))->sign($email, [
        'algorithm' => SymfonyDkimSigner::ALGO_SHA256,
        'header_canon' => 'relaxed',
        'body_canon' => 'relaxed',
    ])->toString();

    expect((new RawMimeDkimSigner)->verify($symfonySigned, (string) $this->domain->dkim_public_key))
        ->toBeTrue('a saját kanonikalizálásunk nem fogadja el a Symfony aláírását');
});

it('a mi aláírásunkat a saját verifikálónk ÉS egy független is elfogadja', function () {
    $raw = "From: no-reply@pelda.hu\r\nTo: cimzett@example.test\r\nSubject: Számla   —   havi\r\n"
        ."Date: Wed, 03 Sep 2026 10:00:00 +0200\r\nMessage-ID: <a@pelda.hu>\r\n\r\n"
        ."Kedves Ügyfél,\r\n\r\nköszönjük.\t\r\n";

    $signer = new RawMimeDkimSigner;
    $signed = $signer->sign($raw, $this->domain);

    expect($signer->verify($signed, (string) $this->domain->dkim_public_key))->toBeTrue();
    expect(DkimVerifier::verify($signed, (string) $this->domain->dkim_public_key))->toBeTrue();
});

/*
 | 3. Független verifikáció.
 */

it('a nyers aláírás publikus kulccsal verifikálható', function () {
    $raw = "From: no-reply@pelda.hu\r\nTo: cimzett@example.test\r\nSubject: Teszt\r\n"
        ."Date: Wed, 03 Sep 2026 10:00:00 +0200\r\nMessage-ID: <a@pelda.hu>\r\n\r\nSzia\r\n";

    $signed = (new RawMimeDkimSigner)->sign($raw, $this->domain);

    expect(DkimVerifier::verify($signed, (string) $this->domain->dkim_public_key))->toBeTrue();
});

it('a törzs módosítása érvényteleníti a nyers aláírást', function () {
    $raw = "From: no-reply@pelda.hu\r\nTo: cimzett@example.test\r\nSubject: Teszt\r\n"
        ."Date: Wed, 03 Sep 2026 10:00:00 +0200\r\nMessage-ID: <a@pelda.hu>\r\n\r\nSzia\r\n";

    $signed = (new RawMimeDkimSigner)->sign($raw, $this->domain);

    expect(DkimVerifier::verify(str_replace('Szia', 'Kattints ide', $signed), (string) $this->domain->dkim_public_key))
        ->toBeFalse('a módosított törzs aláírása is átment — a body hash nem véd');
});

it('a From ÁTÍRÁSA érvényteleníti az aláírást', function () {
    $raw = "From: no-reply@pelda.hu\r\nTo: cimzett@example.test\r\nSubject: Teszt\r\n"
        ."Date: Wed, 03 Sep 2026 10:00:00 +0200\r\nMessage-ID: <a@pelda.hu>\r\n\r\nSzia\r\n";

    $signed = (new RawMimeDkimSigner)->sign($raw, $this->domain);

    expect(DkimVerifier::verify(str_replace('no-reply@pelda.hu', 'ceo@bank.example', $signed), (string) $this->domain->dkim_public_key))
        ->toBeFalse('a feladó átírható volt az aláírás érvénytelenítése nélkül');
});

it('rotáció alatt a RÉGI kulccsal ír alá', function () {
    $this->domain->forceFill([
        'dkim_previous_selector' => 'vm202609',
        'dkim_previous_private_key' => $this->domain->dkim_private_key,
        'dkim_selector' => 'vm202610',
        'dkim_active_selector' => 'vm202609',
        'dkim_rotated_at' => now(),
    ])->save();

    $raw = "From: no-reply@pelda.hu\r\nTo: a@example.test\r\nSubject: R\r\n\r\nSzia\r\n";

    $signed = (new RawMimeDkimSigner)->sign($raw, $this->domain->fresh());

    expect($signed)->toContain('s=vm202609');
    expect(DkimVerifier::verify($signed, (string) $this->domain->dkim_public_key))->toBeTrue();
});

it('kulcs nélküli domainről nincs aláíratlan nyers küldés', function () {
    $this->domain->forceFill(['dkim_selector' => null, 'dkim_private_key' => null])->save();

    expect(fn () => (new RawMimeDkimSigner)->sign("From: a@pelda.hu\r\n\r\nx\r\n", $this->domain->fresh()))
        ->toThrow(RuntimeException::class);
});

function dkimTag(string $mime, string $tag): string
{
    $unfolded = (string) preg_replace('/\r?\n[ \t]+/', ' ', $mime);

    preg_match('/[;\s]'.preg_quote($tag, '/').'=([^;\r\n]+)/', $unfolded, $match);

    return str_replace(' ', '', $match[1] ?? '');
}

it('a MÁSODIK From beszúrása érvényteleníti az aláírást (over-signing)', function () {
    // DKIM/DMARC From-hasítás: a verifikáció az alsó, aláírt `From`-ra épülne, több
    // levélkliens viszont a FELSŐT mutatja — vagyis a levél a mi aláírásunkkal, idegen
    // feladóval jelenne meg.
    $raw = "From: no-reply@pelda.hu\r\nTo: cimzett@example.test\r\nSubject: Teszt\r\n"
        ."Date: Wed, 03 Sep 2026 10:00:00 +0200\r\nMessage-ID: <a@pelda.hu>\r\n\r\nSzia\r\n";

    $signer = new RawMimeDkimSigner;
    $signed = $signer->sign($raw, $this->domain);

    $hasitott = "From: ceo@bank.example\r\n".$signed;

    expect($signer->verify($hasitott, (string) $this->domain->dkim_public_key))
        ->toBeFalse('a beszúrt második From nem érvénytelenítette az aláírást');
});

/*
 | ARANY VEKTOR — független implementációval igazolva.
 |
 | A többi teszt a saját kódunkat méri a saját kódunkkal; ez a literál viszont egy KÜLSŐ
 | referencia-implementációval (Python `dkimpy`, ugyanaz a szabálykészlet, amit az
 | OpenDKIM és a nagy fogadók használnak) VERIFIKÁLT aláírás. Ha a kanonikalizálás bárhol
 | elcsúszik, ez a bájtsor nem jön ki.
 |
 | Miért nem volt elég a többi bizonyíték: a §3.4.5 vektor csak a két kanonikalizáló
 | függvényt méri izoláltan, a Symfony-kereszthivatkozás pedig fordított irányú (a Symfony
 | nem over-signel, tehát a hiányzó-előfordulás ágát sosem aktiválta). Az RFC 6376 §3.5
 | szabálya („a nem létező fejléc NULL bemenet — a nevével és a kettősponttal együtt")
 | ezért csúszott át: minden aláírásunkban ott volt egy `from:` fantomsor, és MINDEN
 | levelünk `dkim=fail`-lel érkezett volna a Gmailhez.
 */

it('az aláírás bájtra egyezik a független implementációval igazolt vektorral', function () {
    // A bemenet SZÁNDÉKOSAN tartalmaz foldolt fejlécet, sorvégi whitespace-t és több záró
    // üres sort: ezek pont azok az alakok, amiken a kanonikalizálás elcsúszhat.
    $raw = "From: no-reply@pelda.hu\r\nTo: cimzett@example.test\r\nSubject: Teszt\r\n"
        ."Date: Wed, 03 Sep 2026 10:00:00 +0200\r\nMessage-ID: <a@pelda.hu>\r\n\r\nSzia\r\n";

    $signed = (new RawMimeDkimSigner)->sign($raw, $this->domain, 1_756_800_000);

    expect(dkimTag($signed, 'h'))->toBe('from:from:to:subject:date:message-id');
    expect(dkimTag($signed, 'bh'))->toBe('EBy5IK6ZGp8zVx7hAjRZhoiyQ+pu+wnx5osP7UdbxSA=');
    expect(dkimTag($signed, 'b'))->toBe('pj1tP7zezoM1uteVS8VK3L5NILF5sSrkgIefRtwSZYgL+BZs+aOGGXmrZ1PErNVysqiLdwiRO5QnOy+aDvhTRrudejMsSeZwRce4hcEGJOyz7PVbPNh1Ewh8wplphUk5/ikbCBP11iVfILJUdPem4RAEhy1TwUF9IJZKrv8+93uJUEtQJTTVosLJEdHvTveb7dUOogF06FcUUdFabE98E3/fR2rNLCHtV3bFs3dy4Sd0vcPBtwCqTJoswzpOz5O5VuF94eZ0ho+ITbXSsh6HyvmcpzyMx5GkH6zh3n6tLLZHQtiRTcCSI58DMDnRidZI15XqPV3zUQPf8RE5e1iQRg==');
});

it('a From nélküli levelet NEM írjuk alá', function () {
    // Az aláírás különben ÁTVIHETŐ lenne: a támadó a mi érvényes aláírásunk mellé beszúrna
    // egy saját `From`-ot, és a levél idegen feladóval, a mi domainünk hitelességével
    // jelenne meg (RFC 6376 §5.4).
    expect(fn () => (new RawMimeDkimSigner)->sign("To: a@example.test\r\n\r\nSzia\r\n", $this->domain))
        ->toThrow(RuntimeException::class);
});

it('a from nélküli h= listát a verifikáló elutasítja', function () {
    $raw = "From: no-reply@pelda.hu\r\nTo: cimzett@example.test\r\nSubject: Teszt\r\n\r\nSzia\r\n";

    $signed = (new RawMimeDkimSigner)->sign($raw, $this->domain);

    // A `h=` lista megcsonkítása: a `from` kiszedésével az aláírás átvihető lenne.
    $csonka = (string) preg_replace('/h=from:\s*from:\s*/', 'h=', $signed, 1);

    expect((new RawMimeDkimSigner)->verify($csonka, (string) $this->domain->dkim_public_key))->toBeFalse();
});
