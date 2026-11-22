<?php

declare(strict_types=1);

use App\Modules\Domains\Models\Domain;
use App\Modules\Sending\Models\Message;
use App\Modules\Sending\Services\DkimSigner;
use App\Modules\Sending\Services\OutboundMimeBuilder;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DkimVerifier;

uses(RefreshDatabase::class);

/*
 | 21. kritérium — DKIM-aláírás a küldésnél, az M0-4 `signingSelector()`-ával.
 |
 | A KAT itt NEM az aláírás-algoritmus újraimplementálása (az ugyanúgy hibázna, mint a
 | tesztelt kód), hanem FÜGGETLEN VERIFIKÁCIÓ: a fix kulcspár publikus felével, openssl-lel
 | ellenőrizzük az aláírást a saját kanonikalizálásunk szerint. Ha a kanonikalizálás rossz,
 | a fogadó oldalon (Gmail) bukna — itt bukik előbb.
 */

beforeEach(function () {
    $workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($workspace);

    $this->message = Message::create([
        'channel' => 'transactional',
        'from_email' => 'no-reply@pelda.hu',
        'subject' => 'Számla',
        'recipients' => ['cimzett@example.test'],
        'options' => ['body_html' => '<p>Szia</p>'],
    ])->withRenderedBodies(['cimzett@example.test' => ['html' => '<p>Szia</p>', 'text' => 'Szia']]);

    $this->domain = Domain::create([
        'name' => 'pelda.hu',
        'status' => 'verified',
        'verified_at' => now(),
        'dkim_selector' => 'vm202609',
        'dkim_private_key' => file_get_contents(base_path('tests/Fixtures/dkim-test-key.pem')),
        'dkim_public_key' => file_get_contents(base_path('tests/Fixtures/dkim-test-key.pub.pem')),
    ]);
});

it('az aláírt levél DKIM-Signature fejlécet kap a helyes selectorral', function () {
    $mime = app(DkimSigner::class)->sign(
        app(OutboundMimeBuilder::class)->build($this->message, 'cimzett@example.test'),
        $this->domain
    );

    expect($mime)->toContain('DKIM-Signature:');
    expect($mime)->toContain('d=pelda.hu');
    expect($mime)->toContain('s=vm202609');
    expect($mime)->toContain('a=rsa-sha256');
    // relaxed/relaxed: a fogadók whitespace-normalizálást várnak, különben egy proxy
    // átírt sortörése érvénytelenné teszi az aláírást.
    expect($mime)->toContain('c=relaxed/relaxed');
});

it('az aláírás FÜGGETLENÜL verifikálható a publikus kulccsal', function () {
    $mime = app(DkimSigner::class)->sign(
        app(OutboundMimeBuilder::class)->build($this->message, 'cimzett@example.test'),
        $this->domain
    );

    expect(DkimVerifier::verify($mime, file_get_contents(base_path('tests/Fixtures/dkim-test-key.pub.pem'))))
        ->toBeTrue('a DKIM-aláírás nem verifikálható a publikus kulccsal');
});

it('a törzs MEGVÁLTOZTATÁSA érvénytelenné teszi az aláírást', function () {
    // Enélkül a teszt akkor is zöld lenne, ha a `bh=` (body hash) rossz.
    $mime = app(DkimSigner::class)->sign(
        app(OutboundMimeBuilder::class)->build($this->message, 'cimzett@example.test'),
        $this->domain
    );

    $tampered = str_replace('Szia', 'Kattints ide', $mime);

    expect($tampered)->not->toBe($mime);
    expect(DkimVerifier::verify($tampered, file_get_contents(base_path('tests/Fixtures/dkim-test-key.pub.pem'))))
        ->toBeFalse('a módosított törzs aláírása is átment — a body hash nem véd');
});

it('rotáció alatt a RÉGI selectorral ír alá', function () {
    // Az új selector DNS-rekordja még nem propagált: az új kulccsal aláírt levél
    // verifikálhatatlan lenne a fogadónál (golden rule 8).
    $this->domain->forceFill([
        'dkim_previous_selector' => 'vm202609',
        'dkim_previous_private_key' => $this->domain->dkim_private_key,
        'dkim_selector' => 'vm202610',
        'dkim_active_selector' => 'vm202609',
        'dkim_rotated_at' => now(),
    ])->save();

    $mime = app(DkimSigner::class)->sign(
        app(OutboundMimeBuilder::class)->build($this->message, 'cimzett@example.test'),
        $this->domain->fresh()
    );

    expect($mime)->toContain('s=vm202609');
    expect($mime)->not->toContain('s=vm202610');
});

it('kulcs nélküli domainről nincs aláíratlan küldés — hangos hiba', function () {
    $this->domain->forceFill(['dkim_private_key' => null, 'dkim_selector' => null])->save();

    expect(fn () => app(DkimSigner::class)->sign(
        app(OutboundMimeBuilder::class)->build($this->message, 'cimzett@example.test'),
        $this->domain->fresh()
    ))->toThrow(RuntimeException::class);
});

it('a From fejléc ALÁ VAN ÍRVA — különben a levél átírható', function () {
    $mime = app(DkimSigner::class)->sign(
        app(OutboundMimeBuilder::class)->build($this->message, 'cimzett@example.test'),
        $this->domain
    );

    // A fejléc FOLDOLVA érkezik (RFC 5322 2.2.3): a sortörés + behúzás egyetlen szóköz.
    $unfolded = (string) preg_replace('/\r?\n[ \t]+/', ' ', $mime);

    // `[;\s]` prefix: a `bh=` tag különben beleillene (`b` + `h=`), és a body-hash-t
    // néznénk fejléclistának.
    preg_match('/[;\s]h=([^;]+);/', $unfolded, $matches);
    $signed = array_map('strtolower', array_map('trim', explode(':', $matches[1] ?? '')));

    foreach (['from', 'to', 'subject', 'date', 'message-id'] as $header) {
        expect(in_array($header, $signed, true))
            ->toBeTrue("a(z) {$header} fejléc nincs aláírva — a levél útközben átírható");
    }
});

it('N1: a Reply-To és a MIME-Version is aláírva utazik, és az aláírás érvényes', function () {
    $this->message->forceFill(['headers' => ['Reply-To' => 'ugyfel@pelda.hu']])->save();

    $mime = app(DkimSigner::class)->sign(
        app(OutboundMimeBuilder::class)->build($this->message, 'cimzett@example.test'),
        $this->domain
    );

    preg_match('/DKIM-Signature:.*?\bh=([^;]+);/is', $mime, $match);
    $signed = array_map(fn ($name) => strtolower(trim($name)), explode(':', (string) preg_replace('/\s+/', '', $match[1] ?? '')));

    // A Reply-To nélkül a válaszok eltéríthetők lennének az aláírás sérülése nélkül.
    expect($signed)->toContain('from')
        ->and($signed)->toContain('subject')
        ->and($signed)->toContain('reply-to')
        ->and($signed)->toContain('mime-version')
        // Ismert korlát (a Symfony a Content-Type-ot a törzs-rész fejléceként kezeli): ha ez
        // egyszer megváltozik, a teszt jelez, és a DkimSigner megjegyzése frissítendő.
        ->and($signed)->not->toContain('content-type')
        ->and(DkimVerifier::verify($mime, file_get_contents(base_path('tests/Fixtures/dkim-test-key.pub.pem'))))
        ->toBeTrue();
});
