<?php

declare(strict_types=1);

use App\Modules\Webhooks\Contracts\HostResolver;
use App\Modules\Webhooks\Services\WebhookUrlGuard;
use App\Modules\Webhooks\Testing\FakeHostResolver;

/*
 | SSRF — a webhook-URL-t az ÜGYFÉL adja meg, a kérést MI indítjuk a saját hálózatunkból.
 |
 | Ez a fájl a KANONIZÁLÁST méri. A tartománylista önmagában nem elég: az `IpUtils::checkIp()`
 | a kettőspontot tartalmazó címet IPv6-ként dolgozza fel, és NEM vetíti vissza IPv4-re —
 | `checkIp('::ffff:169.254.169.254', '169.254.0.0/16')` HAMIS. Vagyis a puszta lista mellett
 | a `https://[::ffff:169.254.169.254]/` átmenne, és a felhő-metadata (benne a példány
 | hitelesítője) kimenne az ügyfél végpontjára — a válasz eleje pedig a kézbesítési naplóból
 | vissza is olvasható.
 */

beforeEach(function () {
    $this->resolver = new FakeHostResolver;
    $this->guard = new WebhookUrlGuard($this->resolver);
});

it('a belső címre mutató URL-t elutasítja', function (string $url) {
    expect($this->guard->allows($url))->toBeFalse();
})->with([
    'loopback' => 'https://127.0.0.1/hook',
    'loopback v6' => 'https://[::1]/hook',
    'metadata' => 'https://169.254.169.254/latest/meta-data/',
    'privát' => 'https://10.0.0.5/hook',
    'privát 172' => 'https://172.16.4.4/hook',
    'privát 192' => 'https://192.168.1.1/hook',
    'CGNAT' => 'https://100.64.0.1/hook',
    'unique local v6' => 'https://[fd00::1]/hook',
    'link-local v6' => 'https://[fe80::1]/hook',
]);

it('az IPv4-mapped IPv6 alakot is elutasítja', function (string $url) {
    expect($this->guard->allows($url))->toBeFalse();
})->with([
    // Ugyanaz a cél, hat írásmódban. Mindegyik a `checkIp()` v4-tartományain KÍVÜL esne.
    'mapped metadata' => 'https://[::ffff:169.254.169.254]/latest/meta-data/iam/',
    'mapped metadata hosszan' => 'https://[0:0:0:0:0:ffff:a9fe:a9fe]/latest/meta-data/',
    'mapped loopback' => 'https://[::ffff:127.0.0.1]:5432/',
    'mapped privát' => 'https://[::ffff:10.0.0.5]/hook',
    'compatible loopback' => 'https://[::127.0.0.1]/hook',
    'unspecified' => 'https://[::]/hook',
    'NAT64' => 'https://[64:ff9b::a9fe:a9fe]/hook',
    '6to4 loopback' => 'https://[2002:7f00:1::]/hook',
]);

it('a numerikus hoszt-alakokat is elutasítja', function (string $url) {
    expect($this->guard->allows($url))->toBeFalse();
})->with([
    // Ezek a feloldó jóindulatán múlnának: a legtöbb `getaddrinfo` a loopbackra viszi őket.
    'decimális' => 'https://2130706433/hook',
    'hexa' => 'https://0x7f.0.0.1/hook',
    'oktális' => 'https://0177.0.0.1/hook',
    'rövidített' => 'https://127.1/hook',
]);

it('csak https megy', function () {
    expect($this->guard->allows('http://hook.example.test/w'))->toBeFalse();
    expect($this->guard->allows('file:///etc/passwd'))->toBeFalse();
    expect($this->guard->allows('gopher://hook.example.test/'))->toBeFalse();
});

it('MINDEN feloldott címet megnéz, nem csak az elsőt', function () {
    // A DNS több A-rekordot adhat, amiből csak az egyik publikus. Ha csak az elsőt
    // néznénk, a támadó egyszerűen a publikusat tenné előre.
    $this->resolver->map('vegyes.example.test', ['203.0.113.10', '127.0.0.1']);

    expect($this->guard->allows('https://vegyes.example.test/hook'))->toBeFalse();
});

it('a publikus URL-t átengedi, és MEGADJA a jóváhagyott címet', function () {
    $this->resolver->map('hook.example.test', ['203.0.113.77']);

    $target = $this->guard->approve('https://hook.example.test/w');

    expect($target->host)->toBe('hook.example.test');
    expect($target->ip)->toBe('203.0.113.77');
    expect($target->port)->toBe(443);
    // A cURL ezt kapja: a kapcsolat az ELLENŐRZÖTT címre épül, a `Host` és az SNI marad a
    // hosztnév — a HTTP-kliens így nem old fel MÁSODSZOR, és a rebinding elveszti a résést.
    expect($target->resolveEntry())->toBe('hook.example.test:443:203.0.113.77');
    expect($target->curlOptions()[CURLOPT_RESOLVE])->toBe(['hook.example.test:443:203.0.113.77']);
    // …és a PROXY kikapcsolva: a `http_proxy` környezeti változó különben kiütné a pinelést
    // (a nevet a proxy oldaná fel), és a rebinding-ablak visszanyílna.
    expect($target->curlOptions()[CURLOPT_PROXY])->toBe('');
});

it('a nem szabványos port is a jóváhagyott címbe kerül', function () {
    $this->resolver->map('hook.example.test', ['203.0.113.77']);

    expect($this->guard->approve('https://hook.example.test:8443/w')->resolveEntry())
        ->toBe('hook.example.test:8443:203.0.113.77');
});

it('a FELOLDÁSI hiba nem ugyanaz, mint az elutasítás', function () {
    // A megkülönböztetés viselkedésbeli: a tiltott cél dead-letter (nem múlik el magától),
    // a feloldási hiba viszont ÁTMENETI — arra újrapróbálás jár, nem az esemény eldobása.
    $this->resolver->map('nincs.example.test', []);

    expect(fn () => $this->guard->approve('https://nincs.example.test/w'))
        ->toThrow(RuntimeException::class);

    expect(fn () => $this->guard->approve('https://127.0.0.1/w'))
        ->toThrow(InvalidArgumentException::class);
});

it('a mapped loopback VALÓBAN a loopbackra csatlakozik', function () {
    // Ez nem elméleti kerülőút. Nyitunk egy szervert a 127.0.0.1-en, majd a `[::ffff:127.0.0.1]`
    // alakra csatlakozunk: ugyanaz a socket veszi fel. Vagyis a guard nélkül a saját belső
    // szolgáltatásaink lennének elérhetők ezen az írásmódon keresztül.
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

    expect($server)->not->toBeFalse();

    $port = (int) explode(':', (string) stream_socket_get_name($server, false))[1];

    $handle = curl_init('http://[::ffff:127.0.0.1]:'.$port.'/');
    curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($handle, CURLOPT_TIMEOUT, 2);
    curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
    curl_exec($handle);

    expect(curl_getinfo($handle, CURLINFO_PRIMARY_IP))->toContain('127.0.0.1');

    curl_close($handle);
    fclose($server);

    // …és pontosan ezt zárja ki a guard.
    expect($this->guard->allows('https://[::ffff:127.0.0.1]:'.$port.'/'))->toBeFalse();
});

it('a guard a konténer VALÓDI feloldóján is használható', function () {
    // A fake nem takarhatja el, hogy a rendszer-feloldó mást ad vissza: a produkciós
    // implementáció ugyanezen az interfészen ül.
    $resolver = app(HostResolver::class);

    expect($resolver->resolve('localhost'))->not->toBeEmpty();
    expect((new WebhookUrlGuard($resolver))->allows('https://localhost/hook'))->toBeFalse();
});

it('a jóváhagyott cím PORTJA a kérés portja — némán nem térhet el', function () {
    // A pinelés `host:port:ip` alakú. Ha a port eltérne a kérés portjától, a cURL CSENDBEN
    // figyelmen kívül hagyná a bejegyzést, és normál DNS-feloldással menne ki — a
    // rebinding-védelem nyom nélkül megszűnne. Ma nem térhet el (mindkettő ugyanabból a
    // `parse_url`-ből jön), és ez a teszt ezt az invariánst rögzíti.
    $this->resolver->map('hook.example.test', ['203.0.113.77']);

    foreach (['https://hook.example.test/w' => 443, 'https://hook.example.test:8443/w' => 8443] as $url => $port) {
        $target = $this->guard->approve($url);

        expect($target->port)->toBe($port);
        expect($target->resolveEntry())->toBe('hook.example.test:'.$port.':203.0.113.77');
        expect($target->resolveEntry())->toContain(':'.$port.':');
    }
});
