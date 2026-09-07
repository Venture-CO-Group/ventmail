<?php

declare(strict_types=1);

use App\Modules\Domains\Contracts\DnsResolver;
use App\Modules\Domains\Testing\FakeDnsResolver;
use App\Modules\Validation\Contracts\MailboxProbe;
use App\Modules\Validation\Data\ProbeResult;
use App\Modules\Validation\Data\ValidationVerdict;
use App\Modules\Validation\Services\AddressValidator;
use App\Modules\Validation\Services\EmailSyntax;
use App\Modules\Validation\Services\TypoSuggester;
use App\Modules\Validation\Testing\FakeMailboxProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 | A5 · 39. kritérium — a NYOLC ellenőrzés.
 |
 | A pontszám és a minősítés KÜLÖN dolog: a pontszám folytonos (a gate küszöbbel dönt), a
 | minősítés az a négy szó, amit az ügyfél lát. Mindkettőt mérjük.
 */

beforeEach(function () {
    $this->dns = new FakeDnsResolver;
    $this->probe = new FakeMailboxProbe;

    $this->app->instance(DnsResolver::class, $this->dns);
    $this->app->instance(MailboxProbe::class, $this->probe);

    $this->validator = app(AddressValidator::class);
});

/*
 | 1. SZINTAXIS
 */

it('a szintaktikailag hibás cím KÉZBESÍTHETETLEN', function (string $email) {
    $verdict = $this->validator->validate($email);

    expect($verdict->classification)->toBe(ValidationVerdict::UNDELIVERABLE);
    expect($verdict->reasons)->toContain('SYNTAX_INVALID');
    // …és szondát NEM küldtünk rá: a hibás címre szondázni a fogadó felé is minket minősít.
    expect($this->probe->count())->toBe(0);
})->with([
    'üres' => '',
    'kukac nélkül' => 'nincs-kukac.hu',
    'két kukac után semmi' => 'a@',
    'kukac előtt semmi' => '@pelda.hu',
    // A `filter_var` ezt ELFOGADJA — pont nélküli domain csak belső hálózaton létezik.
    'pont nélküli domain' => 'a@localhost',
    'dupla pont a helyi részben' => 'a..b@pelda.hu',
    'pont a helyi rész végén' => 'ab.@pelda.hu',
    'szóköz' => 'a b@pelda.hu',
    'vezérlőkarakter' => "a\x00b@pelda.hu",
    'cím-literál' => 'a@[192.0.2.1]',
    'numerikus TLD' => 'a@pelda.12',
    'kötőjel a címke végén' => 'a@pelda-.hu',
    'túl hosszú helyi rész' => str_repeat('a', 65).'@pelda.hu',
]);

it('az érvényes cím átmegy a szintaxis-ellenőrzésen', function (string $email) {
    expect((new EmailSyntax)->valid($email))->toBeTrue();
})->with([
    'egyszerű' => 'kovacs.anna@pelda.hu',
    'pluszos' => 'anna+hirlevel@pelda.hu',
    'aldomain' => 'anna@mail.pelda.hu',
    'kötőjeles domain' => 'anna@pelda-kft.hu',
    'számos' => 'anna123@pelda.hu',
    'ékezetes domain (punycode)' => 'anna@xn--plda-bpa.hu',
]);

/*
 | 2. DOMAIN + MX
 */

it('MX nélkül a cím KÉZBESÍTHETETLEN', function () {
    // A levél sehova nem menne. Ez ténykérdés, nem valószínűség.
    $this->dns->blackhole('nincs-mx.hu');

    $verdict = $this->validator->validate('anna@nincs-mx.hu');

    expect($verdict->classification)->toBe(ValidationVerdict::UNDELIVERABLE);
    expect($verdict->reasons)->toContain('NO_MX_RECORD');
    expect($verdict->risk)->toBe(1.0);
});

it('MX HIÁNYÁBAN az A-rekord implicit MX (RFC 5321 §5.1)', function () {
    // Aki ezt kihagyja, a saját szerverükön futó kis cégek címeit hibásan
    // „kézbesíthetetlennek" minősíti.
    $this->dns->publish('csak-a.hu', 'A', '203.0.113.9');

    expect($this->validator->validate('anna@csak-a.hu')->classification)
        ->not->toBe(ValidationVerdict::UNDELIVERABLE);
});

/*
 | 3–4. SZEREPCÍM és ELDOBHATÓ DOMAIN
 */

it('a szerepcím KOCKÁZATOS, de nem kézbesíthetetlen', function (string $local) {
    // Nem egy ember iratkozott fel, hanem egy csoportos postafiók, ahol bárki megnyomhatja a
    // spam-gombot — a panasz viszont a MI reputációnkat viszi. Nem tiltjuk: jelezzük.
    $verdict = $this->validator->validate($local.'@pelda.hu');

    expect($verdict->classification)->toBe(ValidationVerdict::RISKY);
    expect($verdict->reasons)->toContain('ROLE_ADDRESS');
})->with(['info', 'support', 'admin', 'noreply', 'ugyfelszolgalat', 'penzugy']);

it('az eldobható domain KOCKÁZATOS', function () {
    $verdict = $this->validator->validate('anna@mailinator.com');

    expect($verdict->reasons)->toContain('DISPOSABLE_DOMAIN');
    expect($verdict->classification)->toBeIn([ValidationVerdict::RISKY, ValidationVerdict::UNDELIVERABLE]);
});

/*
 | 5. ELGÉPELÉS-JAVASLAT — a modul legnagyobb üzleti értékű funkciója.
 */

it('felismeri az elgépelt szolgáltatót', function (string $domain, string $suggestion) {
    expect((new TypoSuggester)->suggest($domain))->toBe($suggestion);
})->with([
    'gmial' => ['gmial.com', 'gmail.com'],
    'gmai' => ['gmai.com', 'gmail.com'],
    'gnail' => ['gnail.com', 'gmail.com'],
    'hotmial' => ['hotmial.com', 'hotmail.com'],
    'yaho' => ['yaho.com', 'yahoo.com'],
    'freemial' => ['freemial.hu', 'freemail.hu'],
    'citromai' => ['citromai.hu', 'citromail.hu'],
]);

it('a HELYES domainre nem javasol semmit', function (string $domain) {
    expect((new TypoSuggester)->suggest($domain))->toBeNull();
})->with(['gmail.com', 'freemail.hu', 'pelda.hu', 'sajat-ceg.hu']);

it('a TÁVOLI domainre nem javasol — a hibás javaslat rosszabb, mint a hallgatás', function () {
    // Az ügyfél átírná a JÓ címet rosszra.
    expect((new TypoSuggester)->suggest('valami-egeszen-mas.hu'))->toBeNull();
    expect((new TypoSuggester)->suggest('mail.hu'))->toBeNull();
});

it('a javaslat a TELJES címre szól, a helyi rész változatlan', function () {
    $verdict = $this->validator->validate('kovacs.anna@gmial.com');

    expect($verdict->suggestion)->toBe('kovacs.anna@gmail.com');
    expect($verdict->reasons)->toContain('POSSIBLE_TYPO');
});

/*
 | 6–7. SZONDA és CATCH-ALL
 */

it('a fogadó ELUTASÍTÁSA kézbesíthetetlen', function () {
    // Ennél biztosabb jel nincs: a fogadó kimondta, hogy nincs ilyen postafiók.
    $this->probe->answer('nincs-ilyen@pelda.hu', ProbeResult::rejected(550, '5.1.1 No such user'));

    $verdict = $this->validator->validate('nincs-ilyen@pelda.hu');

    expect($verdict->classification)->toBe(ValidationVerdict::UNDELIVERABLE);
    expect($verdict->reasons)->toContain('MAILBOX_NOT_FOUND');
});

it('a CATCH-ALL domain kockázatos — nem azt jelenti, hogy a cím létezik', function () {
    // A szerver MINDENT elfogad: nem azt mondja, hogy a cím jó, hanem azt, hogy nem árulja
    // el. A különbséget csak a tényleges küldés mutatná meg.
    $this->probe->default = ProbeResult::catchAll();

    $verdict = $this->validator->validate('barmi@catch-all.hu');

    expect($verdict->classification)->toBe(ValidationVerdict::RISKY);
    expect($verdict->reasons)->toContain('CATCH_ALL_DOMAIN');
});

it('a BIZONYTALAN szonda nem tesz kézbesíthetetlenné', function () {
    // Greylisting, időtúllépés, tiltás — ezek a MI korlátaink, nem a címé. A hamis
    // bizonyosság rosszabb: az ügyfél erre hivatkozva törölne ki jó címeket.
    $this->probe->default = ProbeResult::unknown('greylisting');

    $verdict = $this->validator->validate('anna@pelda.hu');

    expect($verdict->classification)->not->toBe(ValidationVerdict::UNDELIVERABLE);
    expect($verdict->reasons)->toContain('PROBE_INCONCLUSIVE');
});

it('a tiszta cím KÉZBESÍTHETŐ', function () {
    $verdict = $this->validator->validate('kovacs.anna@pelda.hu');

    expect($verdict->classification)->toBe(ValidationVerdict::DELIVERABLE);
    expect($verdict->risk)->toBe(0.0);
    expect($verdict->reasons)->toBe([]);
    expect($verdict->checks['syntax'])->toBeTrue();
    expect($verdict->checks['mx'])->toBeTrue();
});

/*
 | 8. A SORREND: ami olcsóbban eldönthető, azt előbb döntjük el.
 */

it('MX nélkül SZONDÁT SEM küldünk', function () {
    // A drága lépés csak akkor fut, ha van értelme. Egy nem létező domainre szondázni
    // felesleges, és a fogadó felé is minket minősít.
    $this->dns->blackhole('nincs-mx.hu');

    $this->validator->validate('anna@nincs-mx.hu');

    expect($this->probe->count())->toBe(0);
});
