<?php

declare(strict_types=1);

use App\Modules\Domains\Models\Domain;
use App\Modules\Sending\Drivers\FakeDriver;
use App\Modules\Sending\Models\Message;
use App\Modules\Sending\Models\RawMimeMessage;
use App\Modules\Smtp\Models\SmtpCredential;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SmtpConversation;

uses(RefreshDatabase::class);

/*
 | A2 · 14–15. kritérium — a PreSendGate az SMTP-úton, és a vezérlő fejlécek.
 |
 | Itt már valódi adatbázis van: a kérdés nem a protokoll, hanem hogy a beadott levél
 | UGYANAZON a kapun megy-e át, mint a REST-hívás (golden rule 2).
 */

beforeEach(function () {
    FakeDriver::reset();

    $this->workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($this->workspace);

    Domain::create(['name' => 'pelda.hu', 'status' => 'verified', 'verified_at' => now()]);

    ['username' => $this->username, 'password' => $this->password] = SmtpCredential::issue('CI');
});

function submit(array $lines, ?string $username = null, ?string $password = null): array
{
    return SmtpConversation::play(
        $username ?? test()->username,
        $password ?? test()->password,
        $lines,
        test()->workspace
    );
}

function mail_lines(array $headers = [], string $body = 'Szia'): array
{
    return SmtpConversation::message($headers, $body);
}

it('a valódi hitelesítővel átmegy a küldés, és a levél eltárolódik', function () {
    $codes = submit(mail_lines());

    expect($codes)->toBe([250, 235, 250, 250, 354, 250]);

    $message = Message::firstOrFail();

    expect($message->status)->toBe('delivered');
    expect($message->channel)->toBe('transactional');
    expect($message->workspace_id)->toBe($this->workspace->getKey());

    // A nyers MIME a levél maga — az SMTP-úton is megőrizzük.
    expect(RawMimeMessage::query()->where('message_uuid', $message->uuid)->firstOrFail()->raw)
        ->toContain('Subject: Teszt');
});

it('a rossz jelszó nem enged küldeni', function () {
    $codes = submit(mail_lines(), password: test()->password.'-hibas');

    expect($codes)->toBe([250, 535, 530, 503, 503, 500, 500, 500, 500, 500]);
    expect(Message::count())->toBe(0);
});

/*
 | 14. kritérium — a PreSendGate az SMTP-úton is dönt.
 */

it('verifikálatlan domainről az SMTP-úton sem megy ki levél', function () {
    Domain::query()->update(['status' => 'pending', 'verified_at' => null]);

    $codes = submit(mail_lines());

    expect($codes)->toBe([250, 235, 250, 250, 354, 550]);
    expect(Message::firstOrFail()->reason)->toBe('DOMAIN_UNVERIFIED');
    expect(FakeDriver::$sent)->toBeEmpty();
});

it('a suppression-listás címzett 550-et kap', function () {
    Suppression::create(['email' => 'cimzett@example.test', 'reason' => 'complaint']);

    $codes = submit(mail_lines());

    expect(end($codes))->toBe(550);
    expect(Message::firstOrFail()->reason)->toBe('SUPPRESSED');
});

it('az ÁTMENETI elutasítás 4xx — a levél nem vész el', function () {
    // A permanens/átmeneti különbség üzenet a kliensnek: a 4xx-re újrapróbál, az 5xx-re
    // véglegesen elteszi. Egy kvóta-csúcs miatt nem szabad elvesznie a levélnek.
    config(['sending.rate_limit.per_minute' => 0]);

    $codes = submit(mail_lines());

    expect(end($codes))->toBe(452);
    expect(Message::firstOrFail()->reason)->toBe('RATE_LIMITED');
});

/*
 | 15. kritérium — vezérlő fejlécek.
 */

it('az X-VentMail-Tag a levélre kerül, a fejléc viszont NEM megy ki', function () {
    submit(mail_lines(['X-VentMail-Tag' => 'szamla, havi', 'X-Sajat' => 'megmarad']));

    $message = Message::firstOrFail();

    expect($message->tags)->toBe(['szamla', 'havi']);
    // A vezérlő fejléc levágva (különben kimenne a címzetthez, és egy továbbküldésnél
    // MÁSODSZOR is érvényesülne), a feladó saját fejléce viszont megmarad.
    expect($message->headers)->not->toHaveKey('X-Ventmail-Tag');
    expect($message->headers)->toHaveKey('X-Sajat');
});

it('a négy tag 550', function () {
    $codes = submit(mail_lines(['X-VentMail-Tag' => 'a,b,c,d']));

    expect(end($codes))->toBe(550);
    expect(Message::count())->toBe(0);
});

it('az X-VentMail-Variables visszajön az eseményben', function () {
    submit(mail_lines(['X-VentMail-Variables' => '{"rendeles":"A-42"}']));

    expect(Message::firstOrFail()->variables)->toBe(['rendeles' => 'A-42']);
});

it('az X-VentMail-Deliverytime ütemez', function () {
    submit(mail_lines(['X-VentMail-Deliverytime' => now()->addHours(3)->toRfc2822String()]));

    expect(Message::firstOrFail()->status)->toBe('scheduled');
    expect(FakeDriver::$sent)->toBeEmpty();
});

it('a múltbeli deliverytime 550', function () {
    $codes = submit(mail_lines(['X-VentMail-Deliverytime' => now()->subHour()->toRfc2822String()]));

    expect(end($codes))->toBe(550);
});

it('az X-VentMail-Testmode nem hívja a drivert', function () {
    submit(mail_lines(['X-VentMail-Testmode' => 'yes']));

    expect(Message::firstOrFail()->testmode)->toBeTrue();
    expect(FakeDriver::$sent)->toBeEmpty();
});

it('az ISMERETLEN vezérlő fejléc elutasítás, nem csendes eldobás', function () {
    // Aki elgépeli, azt hinné, hogy érvényesült.
    $codes = submit(mail_lines(['X-VentMail-Priority' => 'high']));

    expect(end($codes))->toBe(550);
});

/*
 | Idempotencia — az SMTP-nek nincs Idempotency-Key fejléce, a probléma viszont valós.
 */

it('a megismételt levél NEM megy ki kétszer', function () {
    $lines = mail_lines(['Message-ID' => '<abc-123@kliens.test>']);

    submit($lines);
    submit($lines);

    expect(Message::count())->toBe(1);
    expect(FakeDriver::$sent)->toHaveCount(1);
});

it('a MÁS tartalmú levél viszont kimegy', function () {
    submit(mail_lines(['Message-ID' => '<egy@kliens.test>'], body: 'Elso'));
    submit(mail_lines(['Message-ID' => '<ketto@kliens.test>'], body: 'Masodik'));

    expect(Message::count())->toBe(2);
});

/*
 | Tenant-izoláció.
 */

it('idegen workspace hitelesítője nem old fel a mi kontextusunkban sem', function () {
    $masik = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($masik);
    ['username' => $idegen, 'password' => $idegenJelszo] = SmtpCredential::issue('Masik');

    app(WorkspaceContext::class)->set($this->workspace);

    // A hitelesítés SIKERES (a hitelesítő a sajátjához tartozik), de a levél a MÁSIK
    // workspace-be kerül — nem a miénkbe.
    submit(mail_lines(), username: $idegen, password: $idegenJelszo);

    expect(Message::count())->toBe(0);

    app(WorkspaceContext::class)->set($masik);
    expect(Message::count())->toBe(1);
});

it('a visszavont hitelesítő nem hitelesít', function () {
    SmtpCredential::query()->update(['revoked_at' => now()]);

    $codes = submit(mail_lines());

    expect($codes[1])->toBe(535);
    expect(Message::count())->toBe(0);
});

it('a lejárt hitelesítő nem hitelesít', function () {
    SmtpCredential::query()->update(['expires_at' => now()->subDay()]);

    expect(submit(mail_lines())[1])->toBe(535);
});

it('a domainhez kötött hitelesítő MÁS domainről nem küldhet', function () {
    // A hitelesítő domain-kötése a márka- és sub-account-szeparáció eszköze. Ha nem
    // olvasnánk, egy X domainre kiállított hitelesítővel bármelyik verifikált domainről
    // lehetne küldeni ugyanabban a workspace-ben.
    $masik = Domain::create(['name' => 'masik.hu', 'status' => 'verified', 'verified_at' => now()]);

    ['username' => $username, 'password' => $password] = SmtpCredential::issue('Kötött', $masik->getKey());

    $codes = submit(mail_lines(), username: $username, password: $password);

    expect(end($codes))->toBe(550);
    expect(Message::count())->toBe(0);
});

it('a SAJÁT domainjéről viszont küldhet', function () {
    $domain = Domain::query()->where('name', 'pelda.hu')->firstOrFail();

    ['username' => $username, 'password' => $password] = SmtpCredential::issue('Kötött', $domain->getKey());

    $codes = submit(mail_lines(), username: $username, password: $password);

    expect(end($codes))->toBe(250);
});

it('a hitelesítő használata nyomot hagy — nyers IP nélkül', function () {
    submit(mail_lines());

    // A transzport rögzíti; itt közvetlenül hívjuk, mert a protokoll-teszt socket nélkül fut.
    $credential = SmtpCredential::query()->firstOrFail();
    $credential->touchUsage('203.0.113.9');

    $fresh = $credential->fresh();

    expect($fresh->last_used_at)->not->toBeNull();
    expect($fresh->last_ip_hash)->not->toBeNull();
    expect($fresh->last_ip_hash)->not->toBe('203.0.113.9');
});

it('EGY From fejlécen belül sem lehet két feladót elrejteni', function () {
    // A mohó `<...>` illesztés miatt nálunk a MÁSODIK cím lett a feladó (tehát a domain-kapu
    // és a hitelesítő domain-kötése is átment), a fogadók egy része viszont az ELSŐT
    // jeleníti meg — feladó-hamisítás a saját DKIM-ünkkel aláírva.
    foreach (['<ceo@bank.example> <hello@pelda.hu>', 'ceo@bank.example <hello@pelda.hu>'] as $from) {
        $codes = submit(mail_lines(['From' => $from]));

        expect(end($codes))->toBe(550);
    }

    expect(Message::count())->toBe(0);
});

it('az SMTP relay ELUTASÍTJA az ügyfél leiratkozó fejlécét', function () {
    // A HARMADIK belépési pont, és korábban az EGYETLEN, ami nyitva maradt. A
    // `/v1/messages` és a `/v1/messages.mime` már elutasította; a relay ugyanazt a
    // `MimeParser`-t hívja, de a jelzést nem olvasta.
    //
    // Mérve, végponttól végpontig: egy idegen workspace élő tokenjével a levél `250 Ok`-val
    // átment, a `messages.headers` ÜRES lett (tehát EGYETLEN KAPU SEM LÁTTA), a kimenő
    // bájtok viszont vitték a tokent — és a one-click egy IDEGEN tenant
    // suppression-listájára írt.
    //
    // A „relay rendben van, mert ott a csatorna fixen tranzakciós" érv téves: a fix csatorna
    // azt akadályozza meg, hogy MI írjuk a fejlécet, nem azt, hogy az ügyfél.
    $codes = submit(mail_lines([
        'List-Unsubscribe' => '<https://tamado.example/u/ELKAPOTT-TOKEN>',
        'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
    ]));

    // A DATA végén 550: a levél NEM kerül sorba.
    expect(end($codes))->toBe(550);
    expect(Message::count())->toBe(0);
});

it('a leiratkozó fejléc BETŰZÉSTŐL függetlenül elakad a relayen', function () {
    // Egy zár, egy normalizálás: a `UnsubscribeHeaderPolicy` ugyanazt mondja itt, mint a
    // két HTTP-úton.
    foreach (['list-unsubscribe', 'LIST-UNSUBSCRIBE', 'List-Unsubscribe-Post'] as $name) {
        FakeDriver::reset();

        $codes = submit(mail_lines([$name => '<https://tamado.example/u/X>']));

        expect(end($codes))->toBe(550, $name);
    }

    expect(Message::count())->toBe(0);
});
