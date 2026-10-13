<?php

declare(strict_types=1);

use App\Modules\Analytics\Data\AnalyticsRange;
use App\Modules\Analytics\Services\AnalyticsSections;
use App\Modules\Audience\Models\Contact;
use App\Modules\Audience\Services\AudienceInsights;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\AnalyticsFixtures;

uses(RefreshDatabase::class);

/*
 | v1.1 · 8. modul — közönség-növekedés tetszőleges időszakra (≤ 366 nap) és a lemorzsolódás
 | (D232: Σ leiratkozott / induló feliratkozott).
 */

function vmContact(string $email, Carbon $created, ?Carbon $unsubscribed = null): void
{
    $contact = Contact::create(['email' => $email, 'status' => $unsubscribed ? 'unsubscribed' : 'subscribed']);
    $contact->forceFill(['created_at' => $created, 'unsubscribed_at' => $unsubscribed])->saveQuietly();
}

it('zárt időszakra, a megadott időzóna napjaira bont, üres napot nullával tölt', function () {
    Carbon::setTestNow('2026-10-13 12:00:00');
    AnalyticsFixtures::tenant();

    // 2026-10-10 23:30 UTC = 2026-10-11 01:30 Budapesten → a 11-ei naphoz tartozik.
    vmContact('a@pelda.hu', Carbon::parse('2026-10-10 23:30:00', 'UTC'));
    vmContact('b@pelda.hu', Carbon::parse('2026-10-12 10:00:00', 'UTC'), Carbon::parse('2026-10-12 11:00:00', 'UTC'));
    vmContact('kivul@pelda.hu', Carbon::parse('2026-10-01 10:00:00', 'UTC'));

    $rows = app(AudienceInsights::class)->growthBetween(Carbon::parse('2026-10-10'), Carbon::parse('2026-10-12'), 'Europe/Budapest');

    expect($rows)->toBe([
        ['date' => '2026-10-10', 'new' => 0, 'unsubscribed' => 0, 'net' => 0],
        ['date' => '2026-10-11', 'new' => 1, 'unsubscribed' => 0, 'net' => 1],
        ['date' => '2026-10-12', 'new' => 1, 'unsubscribed' => 1, 'net' => 0],
    ]);

    Carbon::setTestNow();
});

it('a growth() a growthBetween()-re delegál (az alakja nem változik)', function () {
    AnalyticsFixtures::tenant();
    vmContact('c@pelda.hu', now()->subDays(2));

    $rows = app(AudienceInsights::class)->growth(7, 'UTC');

    expect($rows)->toHaveCount(7)
        ->and(array_sum(array_column($rows, 'new')))->toBe(1)
        ->and(array_keys($rows[0]))->toBe(['date', 'new', 'unsubscribed', 'net']);
});

it('366 napnál hosszabb időszakot elutasít', function () {
    AnalyticsFixtures::tenant();

    app(AudienceInsights::class)->growthBetween(now()->subDays(400), now(), 'UTC');
})->throws(InvalidArgumentException::class);

it('a lemorzsolódás nevezője az INDULÓ feliratkozott-szám', function () {
    AnalyticsFixtures::tenant();

    foreach (range(1, 8) as $i) {
        vmContact("regi{$i}@pelda.hu", now()->subDays(60));
    }

    vmContact('uj@pelda.hu', now()->subDays(2));
    vmContact('lemorzsolodott@pelda.hu', now()->subDays(60), now()->subDays(1));
    vmContact('lemorzsolodott2@pelda.hu', now()->subDays(60), now()->subDays(1));

    $section = app(AnalyticsSections::class)->audience(AnalyticsRange::resolve('30', null, null, 'UTC'));

    // Ma 9 feliratkozott; az ablakban +1 új, −2 leiratkozó → induláskor 9 − (1 − 2) = 10.
    expect($section['available'])->toBeTrue()
        ->and($section['churn']['subscribedAtStart'])->toBe(10)
        ->and($section['churn']['unsubscribed'])->toBe(2)
        ->and($section['churn']['rate'])->toBe(20.0)
        ->and(array_column($section['engagement'], 'key'))->toBe(['active', 'inactive', 'never_opened', 'not_mailed']);
});
