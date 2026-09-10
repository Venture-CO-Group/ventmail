<?php

declare(strict_types=1);

use App\Modules\Sending\Services\DomainNormalizer;

/*
 | KAT-vektorok a domain-kanonikalizálásra.
 |
 | Ez a függvény MINDEN domain-szintű engedélyezési döntés alapja: domain-kapu, hitelesítő
 | domain-kötése, per-fogadó-domain throttle, DKIM-domain, suppression- és consent-kulcs.
 | A kimenete ráadásul futtatókörnyezet-függő lenne, ha a zászlókat az ICU-ra bíznánk
 | (`IDNA_DEFAULT` ICU < 76 alatt TRANZICIONÁLIS) — vagyis egy base-image frissítés
 | csendben megváltoztatná az egész rendszer engedélyezési szemantikáját.
 |
 | Ezért itt RÖGZÍTETT bemenet → kimenet tábla van. Ha bármelyik sor elmozdul, az nem
 | „formázási eltérés", hanem az jelenti, hogy valaki más domain nevében küldhet.
 */

dataset('domain-vektorok', [
    // Egyszerű ASCII: csak kisbetűsítés.
    'ascii' => ['pelda.hu', 'pelda.hu'],
    'nagybetűs ascii' => ['PELDA.HU', 'pelda.hu'],
    'aldomain' => ['mail.pelda.co.uk', 'mail.pelda.co.uk'],

    // IDN: unicode → punycode, oda-vissza egyezéssel.
    'magyar ékezet' => ['példa.hu', 'xn--plda-bpa.hu'],
    'nagybetűs IDN' => ['PÉLDA.HU', 'xn--plda-bpa.hu'],
    'már punycode' => ['xn--plda-bpa.hu', 'xn--plda-bpa.hu'],

    // NONTRANZICIONÁLIS UTS46: a `ß` és a `ς` ÖNMAGA marad. Tranzicionális módban a
    // `straße.de` a `strasse.de`-vé válna — két külön regisztrálható domain esne egybe,
    // és aki a `strasse.de`-t hitelesítette, a `straße.de` nevében küldhetne.
    'eszett marad' => ['straße.de', 'xn--strae-oqa.de'],
    'strasse külön domain' => ['strasse.de', 'strasse.de'],
    'végső szigma marad' => ['σος.gr', 'xn--0xagb.gr'],

    // ÉRVÉNYTELEN (üres eredmény): mindegyik olyan alak, ami MÁS domaint mosna össze
    // egy hitelesítettel.
    'vezető pont' => ['.pelda.hu', ''],
    'záró pont' => ['pelda.hu.', ''],
    'dupla pont' => ['pelda..hu', ''],
    'vezető szóköz' => [' pelda.hu', ''],
    'záró szóköz' => ['pelda.hu ', ''],
    'fullwidth p' => ['ｐelda.hu', ''],
    'kelvin jel' => ["\u{212A}elvin.com", ''],
    'zero-width joiner' => ["exa\u{200D}mple.com", ''],
    'TLD nélkül' => ['pelda', ''],
    'üres' => ['', ''],
    'címliterál' => ['[127.0.0.1]', ''],
    'aláhúzás' => ['pel_da.hu', ''],
    'túl hosszú label' => [str_repeat('a', 64).'.hu', ''],
]);

it('a domain-kanonikalizálás vektorai', function (string $input, string $expected) {
    expect(DomainNormalizer::canonical($input))->toBe($expected);
})->with('domain-vektorok');

it('a leképezés INJEKTÍV: két különböző domain sosem esik egy alakra', function () {
    // Ha ez sérül, valaki más domain nevében küldhet — a kapu ugyanazt a sort találná meg.
    $inputs = ['pelda.hu', 'példa.hu', 'straße.de', 'strasse.de', 'mail.pelda.hu', 'xn--plda-bpa.hu'];

    $canonical = array_map(DomainNormalizer::canonical(...), $inputs);
    $distinct = array_unique(array_filter($canonical));

    // A `példa.hu` és az `xn--plda-bpa.hu` UGYANAZ a domain (két írásmód), ezért 5 marad.
    expect(count($distinct))->toBe(5);
});

it('a helyi rész a KÜLDÉSI úton érintetlen, a LISTÁKON kisbetűs', function () {
    // Két különböző szabály, két különböző célra:
    //  - küldés: a helyi részt csak a fogadó értelmezheti (RFC 5321 §2.4);
    //  - suppression/consent: a lista kis/nagybetű-érzéketlen, különben triviálisan
    //    megkerülhető lenne (`User@x.hu` leiratkozása után `user@x.hu`-ra küldenénk).
    expect(DomainNormalizer::email('Ünnepi.Kovács@PÉLDA.hu'))->toBe('Ünnepi.Kovács@xn--plda-bpa.hu');
    expect(DomainNormalizer::listKey('Ünnepi.Kovács@PÉLDA.hu'))->toBe('ünnepi.kovács@xn--plda-bpa.hu');
});

it('érvénytelen domainnél a cím VÁLTOZATLAN marad — a hibát a kapu jelzi', function () {
    // Csendben csonkolni a legrosszabb: a hívó azt hinné, elfogadtuk.
    expect(DomainNormalizer::email('user@.pelda.hu'))->toBe('user@.pelda.hu');
});

/*
 | KAT-vektorok a HOZZÁFÉRÉSI kulcsra (`authKey`).
 |
 | Ez a kulcs dönti el, hogy egy meghívó KIÉ: a beváltás és a belépési jogcím is pontos
 | egyezést néz rajta. A hibairánya ellentétes a `listKey()`-ével — ott a túl-illesztés
 | fail-SAFE (több cím tiltódik a suppression-listán), itt fail-OPEN (több embernek jár
 | hozzáférés). A security-review mérte meg, mit jelentett a különbség figyelmen kívül
 | hagyása: a KELVIN SIGN-os `bo\u{212A}@corp.com` az `mb_strtolower` után `bok@corp.com`,
 | vagyis egy admin-meghívót egy MÁSIK postafiók tulajdonosa válthatott volna be.
 |
 | Ezek a sorok ezért nem „formázási elvárások": mindegyik egy egykarakteres változtatás
 | ellen véd (`strtolower` ↔ `mb_strtolower`, `$` ↔ `\z`, ASCII-szűrő megléte).
 */
dataset('auth-kulcs-vektorok', [
    // ELFOGADOTT: ASCII helyi rész + kanonikus domain.
    'egyszerű' => ['bob@corp.com', 'bob@corp.com'],
    'nagybetűs helyi rész' => ['Bob@corp.com', 'bob@corp.com'],
    'nagybetűs domain' => ['bob@CORP.COM', 'bob@corp.com'],
    'körülvevő szóköz' => ['  bob@corp.com  ', 'bob@corp.com'],
    'pluszos címzés MEGMARAD' => ['bob+hirlevel@corp.com', 'bob+hirlevel@corp.com'],
    'pont a helyi részben MEGMARAD' => ['nagy.bela@corp.com', 'nagy.bela@corp.com'],
    'IDN domain punycode-ra' => ['bob@bücher.de', 'bob@xn--bcher-kva.de'],
    'már punycode' => ['bob@xn--bcher-kva.de', 'bob@xn--bcher-kva.de'],

    // ELUTASÍTOTT (üres kulcs): nincs TISZTA leképezés, tehát nincs hozzáférés.
    'KELVIN SIGN a helyi részben' => ["bo\u{212A}@corp.com", ''],
    'török nagy I pont felett' => ["bo\u{0130}@corp.com", ''],
    'fullwidth helyi rész' => ["\u{FF42}ob@corp.com", ''],
    'ékezet a helyi részben' => ['ünnepi@corp.com', ''],
    'záró sortörés' => ["bob\n@corp.com", ''],
    'nullbájt' => ["bob\0@corp.com", ''],
    'CRLF' => ["bob\r\n@corp.com", ''],
    'szóköz idézőjelben' => ['"john smith"@corp.com', ''],
    'üres helyi rész' => ['@corp.com', ''],
    'domain nélkül' => ['bob', ''],
    'címliterál' => ['bob@[10.0.0.1]', ''],
    'záró pont a domainen' => ['bob@corp.com.', ''],
    'fullwidth domain' => ['bob@ｃorp.com', ''],
    'üres' => ['', ''],
]);

it('a hozzáférési kulcs vektorai', function (string $input, string $expected) {
    expect(DomainNormalizer::authKey($input))->toBe($expected);
})->with('auth-kulcs-vektorok');

it('a hozzáférési kulcs INJEKTÍV a unicode-változatokra', function () {
    /*
     | A `listKey()` ezen a halmazon ÜTKÖZIK (mind `bok@corp.com` lesz) — és ez ott helyes,
     | mert a suppression-lista túl-illesztése biztonságos irányba téved. Az `authKey()`-nek
     | viszont vagy pontosan egyeznie kell, vagy semmit nem szabad adnia.
     */
    $variants = [
        'bok@corp.com',
        "bo\u{212A}@corp.com",
        "\u{FF42}ok@corp.com",
        "bo\u{0138}@corp.com",
    ];

    $keys = array_values(array_filter(array_map(
        fn (string $email) => DomainNormalizer::authKey($email),
        $variants
    )));

    expect($keys)->toBe(['bok@corp.com'])
        // A `listKey()` ugyanezen a halmazon TÖBB címet mos egy kulcsra — a különbség
        // szándékos, és ez a sor rögzíti, hogy nem véletlenül lett ugyanaz a két függvény.
        ->and(count(array_unique(array_map(
            fn (string $email) => DomainNormalizer::listKey($email),
            $variants
        ))))->toBeLessThan(count($variants));
});
