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
