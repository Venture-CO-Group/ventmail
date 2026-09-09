<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Deliverability\Models\BlocklistCheck;
use App\Modules\Deliverability\Models\SeedTest;
use App\Modules\Identity\Enums\Role;
use App\Modules\IpPools\Models\IpAddress;
use App\Modules\IpPools\Models\IpPool;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 | M4-2 · KÉZBESÍTHETŐSÉG, IP-POOLOK, CÍMELLENŐRZÉS, SZÁMLÁZÁS.
 |
 | Négy képernyő, amit az üzemeltető akkor néz meg, amikor valami nem stimmel — és ami
 | ezért nem mutathat mást, mint amit a motor lát.
 */

function opsUser(Workspace $workspace, string $role = 'owner'): User
{
    $user = User::create([
        'sso_issuer' => 'https://sso.teszt.invalid',
        'sso_subject' => 'ops-'.uniqid(),
        'name' => 'Üzemeltető',
        'email' => 'ops-'.uniqid().'@pelda.hu',
        'locale' => 'hu',
    ]);

    $workspace->members()->create(['user_id' => $user->getKey(), 'roles' => [$role]]);

    return $user;
}

it('az IP-képernyő a KIHASZNÁLTSÁGOT mutatja, nem csak a warmup napját', function () {
    /*
     | A napi keret az, ami a levelet CSÚSZTATJA — a warmup-nap önmagában nem mondja meg,
     | hogy ma még mennyi fér bele.
     */
    $workspace = Workspace::factory()->create();
    $user = opsUser($workspace);

    app(WorkspaceContext::class)->runFor($workspace, function () {
        $pool = IpPool::create(['name' => 'Saját', 'type' => 'dedicated']);

        IpAddress::create([
            'ip_pool_id' => $pool->getKey(),
            'address' => '203.0.113.10',
            'hostname' => 'mta1.pelda.hu',
            'daily_limit' => 1000,
            'sent_today' => 780,
            'warmup_state' => 'warming',
        ]);
    });

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/ip-pools')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('IpPools/Index')
            ->has('pools', 1)
            ->has('addresses', 1)
            ->where('addresses.0.utilisation', 78)
            ->where('addresses.0.sentToday', 780)
            ->where('addresses.0.dailyLimit', 1000)
        );
});

it('a kézbesíthetőségi képernyő CSAK a saját és a megosztott poolok IP-jeit mutatja', function () {
    /*
     | A blocklist-sor SZÁNDÉKOSAN nem tenant-adat (a küldő IP a mi infrastruktúránk), de
     | ez nem jelenti azt, hogy MINDEN tenant IP-jét meg kell mutatni mindenkinek: a
     | képernyő a workspace pooljaira szűkít.
     */
    $enyem = Workspace::factory()->create();
    $idegen = Workspace::factory()->create();
    $user = opsUser($enyem);

    $sajatIp = app(WorkspaceContext::class)->runFor($enyem, function () {
        $pool = IpPool::create(['name' => 'Saját', 'type' => 'dedicated']);

        return IpAddress::create(['ip_pool_id' => $pool->getKey(), 'address' => '203.0.113.11', 'hostname' => 'a.pelda.hu']);
    });

    $idegenIp = app(WorkspaceContext::class)->runFor($idegen, function () {
        $pool = IpPool::create(['name' => 'Idegen', 'type' => 'dedicated']);

        return IpAddress::create(['ip_pool_id' => $pool->getKey(), 'address' => '203.0.113.99', 'hostname' => 'b.pelda.hu']);
    });

    foreach ([$sajatIp, $idegenIp] as $ip) {
        BlocklistCheck::create([
            'ip_address_id' => $ip->getKey(),
            'target' => (string) $ip->address,
            'zone' => 'zen.spamhaus.org',
            'listed' => true,
            'checked_at' => now(),
        ]);
    }

    app(WorkspaceContext::class)->runFor($enyem, fn () => SeedTest::create([
        'name' => 'Heti mérés',
        'status' => 'finished',
        'inbox' => 8,
        'spam' => 1,
        'promotions' => 0,
        'missing' => 1,
        'finished_at' => now(),
    ]));

    $this->actingAs($user)->withSession(['workspace_uuid' => $enyem->uuid])
        ->get('/deliverability')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Deliverability/Index')
            ->where('blocklist.listedCount', 1)
            ->where('blocklist.items.0.address', '203.0.113.11')
            // A HIÁNYZÓ is benne van a nevezőben: 8 inbox / 10 összes = 80%.
            ->where('seedTests.0.inboxRate', 80)
        );
});

it('a címellenőrzés VERDIKTET ad, és javaslatot az elgépelt címre', function () {
    $workspace = Workspace::factory()->create();
    $user = opsUser($workspace);

    /*
     | A VÁLASZ MAGA A KÉPERNYŐ: az Inertia a POST válaszát oldalként jeleníti meg, tehát az
     | eredmény ott van, ahol a kérdést feltették — és egy frissítés (GET) után eltűnik, a
     | megadott címmel együtt.
     */
    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->post('/validation', ['email' => 'valaki@gmial.com'])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Validation/Index')
            ->where('verdict.address', 'valaki@gmial.com')
            // AZ ELGÉPELÉS-JAVASLAT a legnagyobb üzleti értékű mező: enélkül a cím a
            // listán örökre bounce-olna.
            ->where('verdict.did_you_mean', 'valaki@gmail.com')
        );

    // FRISSÍTÉS UTÁN nincs verdikt — a cím nem ragad be a képernyőbe.
    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/validation')
        ->assertInertia(fn ($page) => $page->where('verdict', null));
});

it('a címellenőrzés a TARTALMI szerepeknek nem jár', function () {
    // A szonda RBL-kockázatot hordoz és mérve van (metering) — nem futtathatja bárki.
    $workspace = Workspace::factory()->create();
    $author = opsUser($workspace, Role::Author->value);

    $this->actingAs($author)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/validation')
        ->assertForbidden();
});

it('a számlázás CSAK az ownernek jár — az admin sem költhet', function () {
    $workspace = Workspace::factory()->create();
    $owner = opsUser($workspace);
    $admin = opsUser($workspace, Role::Admin->value);

    app(WorkspaceContext::class)->runFor($workspace, fn () => Invoice::create([
        'period' => '2026-09',
        'usage' => ['sends_counter' => 1200],
        'dunning_state' => 'open',
        'due_at' => now()->addDays(14),
        // A `closed_at` NOT NULL: a kivonat LEZÁRT periódusra készül (C1 — a nyitott
        // periódus fogyása még változhat, tehát nem számlázható).
        'closed_at' => now(),
    ]));

    $this->actingAs($owner)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/billing')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Billing/Index')
            ->has('invoices', 1)
            ->where('invoices.0.period', '2026-09')
            ->where('suspended', false)
            ->has('remainingQuota')
        );

    $this->actingAs($admin)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/billing')
        ->assertForbidden();
});

it('a FELFÜGGESZTÉS a számlázási képernyőn látszik, nem csak a hibaüzenetben', function () {
    /*
     | A PreSendGate 4. kapuja a felfüggesztett fióknak `QUOTA_EXCEEDED`-et ad. Ha ezt csak
     | a hibaüzenetből tudná meg az ügyfél, a támogatás értesülne róla először.
     */
    $workspace = Workspace::factory()->create();
    $owner = opsUser($workspace);

    app(WorkspaceContext::class)->runFor($workspace, fn () => Invoice::create([
        'period' => '2026-08',
        'usage' => [],
        'dunning_state' => 'suspended',
        'due_at' => now()->subDays(30),
        'suspended_at' => now()->subDay(),
        'closed_at' => now()->subDays(30),
    ]));

    $this->actingAs($owner)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/billing')
        ->assertInertia(fn ($page) => $page->where('suspended', true));
});
