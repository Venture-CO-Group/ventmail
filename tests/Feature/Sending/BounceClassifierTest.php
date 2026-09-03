<?php

declare(strict_types=1);

use App\Modules\Sending\Data\BounceClass;
use App\Modules\Sending\Services\BounceClassifier;

/*
 | 20. kritérium — a bounce-osztályozó. Ez a tábla ÜZLETI döntés, nem szépítés: a `hard`
 | AZONNALI suppression (a cím nem létezik, minden további küldés a reputációt rombolja),
 | a `soft` viszont N próba után. Ha a kettő összekeveredik, vagy egy létező postafiókot
 | zárunk ki véglegesen (elveszett vevő), vagy egy nem létező címre küldünk örökké
 | (blocklist).
 |
 | Az ENHANCED kód (X.Y.Z) ELŐZI a nyers kódot: a „550 5.2.2 over quota" nem hard bounce,
 | a postafiók csak tele van, holnap újra kézbesíthető.
 */

dataset('bounce-vektorok', [
    // nyers kód, enhanced, szöveg → osztály
    'nem létező cím' => [550, '5.1.1', 'User unknown', BounceClass::Hard],
    'nem létező domain' => [550, '5.1.2', 'Host unknown', BounceClass::Hard],
    'enhanced nélküli 550' => [550, null, 'No such user here', BounceClass::Hard],
    'telített postafiók 5xx' => [550, '5.2.2', 'Mailbox full', BounceClass::MailboxFull],
    'telített postafiók 4xx' => [452, '4.2.2', 'over quota', BounceClass::MailboxFull],
    'ideiglenes hiba' => [452, null, 'Too many recipients', BounceClass::Soft],
    'greylisting' => [451, '4.7.1', 'Greylisted, try again later', BounceClass::Soft],
    'blocklist' => [554, '5.7.1', 'Your IP is on our blocklist', BounceClass::Blocked],
    'spamnak minősítve' => [550, '5.7.1', 'Message rejected as spam', BounceClass::Spam],
    'tartalmi tiltás' => [550, '5.6.0', 'Content rejected', BounceClass::Spam],
    'policy' => [550, '5.7.0', 'Policy violation: DMARC fail', BounceClass::Policy],
    'ismeretlen 5xx' => [599, null, 'Something odd', BounceClass::Hard],
    'ismeretlen 4xx' => [499, null, 'Something odd', BounceClass::Soft],
]);

it('a bounce-osztályozó vektorai', function (int $code, ?string $enhanced, string $text, BounceClass $expected) {
    expect(app(BounceClassifier::class)->classify($code, $text, $enhanced))->toBe($expected);
})->with('bounce-vektorok');

it('az enhanced kód ERŐSEBB a nyers kódnál', function () {
    // Ugyanaz az 550, két teljesen más következmény.
    $classifier = app(BounceClassifier::class);

    expect($classifier->classify(550, 'over quota', '5.2.2')->suppressesImmediately())->toBeFalse();
    expect($classifier->classify(550, 'user unknown', '5.1.1')->suppressesImmediately())->toBeTrue();
});

it('a hard bounce azonnal suppressiont von maga után, a soft nem', function () {
    expect(BounceClass::Hard->suppressesImmediately())->toBeTrue();
    expect(BounceClass::Blocked->suppressesImmediately())->toBeFalse();
    expect(BounceClass::Soft->suppressesImmediately())->toBeFalse();
    expect(BounceClass::MailboxFull->suppressesImmediately())->toBeFalse();
});

it('a spam- és policy-osztály KÜLÖN kategória, nem hard', function () {
    // Ezek nem a címről szólnak, hanem RÓLUNK: a cím jó, a küldő oldalon van a baj.
    // Ha hardként kezelnénk, elveszítenénk a címzettet egy sajátoldali hiba miatt.
    foreach ([BounceClass::Spam, BounceClass::Policy, BounceClass::Blocked] as $class) {
        expect($class->suppressesImmediately())->toBeFalse();
        expect($class->indicatesSenderProblem())->toBeTrue();
    }

    expect(BounceClass::Hard->indicatesSenderProblem())->toBeFalse();
});
