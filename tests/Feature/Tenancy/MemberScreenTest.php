<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Actions\AuthorizeSsoSubject;
use App\Modules\Tenancy\Actions\CreateSubAccount;
use App\Modules\Tenancy\Actions\InviteMember;
use App\Modules\Tenancy\Actions\RedeemInvitations;
use App\Modules\Tenancy\Actions\RemoveMember;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Models\WorkspaceInvitation;
use App\Modules\Tenancy\Models\WorkspaceMember;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

/*
 | M4-6 · TAGOK, MEGHÍVÓK, SUB-ACCOUNTOK.
 |
 | A tagság a ventMail SAJÁT táblája (golden rule 1) — a ventSSO csak szerepet ad, a
 | hozzáférést itt döntjük el. A képernyő ezért nem „kényelem": eddig egy új kolléga
 | felvétele CSAK adatbázisból volt lehetséges.
 */

function memberUser(Workspace $workspace, string $role = 'owner', bool $verified = true): User
{
    $user = User::create([
        'sso_issuer' => 'https://sso.teszt.invalid',
        'sso_subject' => 'tag-'.uniqid(),
        'name' => 'Tag',
        'email' => 'tag-'.uniqid().'@pelda.hu',
        'locale' => 'hu',
    ]);

    // A `email_verified_at` szándékosan NEM tömegesen kitölthető (a verifikáltság
    // jogosultsági jel, nem beállítás) — a teszt is a `forceFill` úton írja.
    if ($verified) {
        $user->forceFill(['email_verified_at' => now()])->save();
    }

    $workspace->members()->create(['user_id' => $user->getKey(), 'roles' => [$role]]);

    return $user;
}

it('a tagok képernyő csak azokat a szerepeket kínálja fel, amiket a hívó ki is oszthat', function () {
    /*
     | Az `admin` nem oszthat `owner`-t (annak `billing.manage`-e és `workspace.delete`-je
     | van). A listát a SZERVER szűri — nem azért, mert a felület a védelem (a döntést az
     | `AssignWorkspaceRoles` hozza minden kérésnél), hanem hogy a felhasználó ne egy
     | hibaüzenetből tudja meg, mit nem tehet.
     */
    $workspace = Workspace::factory()->create();
    $admin = memberUser($workspace, 'admin');

    $this->actingAs($admin)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/members')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenancy/Members')
            ->has('members', 1)
            // Az `owner` HIÁNYZIK a listából, a nála gyengébb szerepek pedig ott vannak.
            ->where('assignableRoles', ['admin', 'developer', 'editor', 'author', 'analyst'])
        );
});

it('az UTOLSÓ TULAJDONOS nem fokozható le és nem távolítható el', function () {
    /*
     | Enélkül az owner egyetlen kattintással `analyst`-tá tehette magát, és a
     | workspace-ben SENKI nem tudott többé tagot kezelni vagy számlázni — a saját
     | felületén nem volt visszaút, csak platform-adminnal.
     */
    $workspace = Workspace::factory()->create();
    $owner = memberUser($workspace, 'owner');
    $member = WorkspaceMember::query()->where('user_id', $owner->getKey())->firstOrFail();

    $this->actingAs($owner)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/members')
        ->post('/members/'.$member->getKey().'/roles', ['roles' => ['analyst']])
        ->assertRedirect('/members');

    expect($member->fresh()->roles())->toBe(['owner']);

    $this->actingAs($owner)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/members')
        ->delete('/members/'.$member->getKey())
        ->assertRedirect('/members');

    expect(WorkspaceMember::query()->whereKey($member->getKey())->exists())->toBeTrue();
});

it('MÁSIK WORKSPACE tagsága nem törölhető az azonosítójával', function () {
    /*
     | A `workspace_members` a scope FORRÁSA, nem az alanya: nincs rajta global scope,
     | tehát egy idegen `id` egy másik tenant tagját törölné. Golden rule 6 szerint ez
     | blokkoló szivárgás.
     */
    $mine = Workspace::factory()->create();
    $theirs = Workspace::factory()->create();

    $owner = memberUser($mine, 'owner');
    $stranger = memberUser($theirs, 'owner');
    $foreign = WorkspaceMember::query()->where('user_id', $stranger->getKey())->firstOrFail();

    Auth::login($owner);

    app(WorkspaceContext::class)->runFor($mine, function () use ($foreign) {
        expect(fn () => app(RemoveMember::class)($foreign))->toThrow(AuthorizationException::class);
    });

    expect(WorkspaceMember::query()->whereKey($foreign->getKey())->exists())->toBeTrue();
});

it('a meghívó a CÍMHEZ szól, és a szerep-korlát MÁR A MEGHÍVÁSKOR érvényes', function () {
    /*
     | Ha a korlát csak a beváltáskor futna, az `admin` `owner`-meghívót írhatna, és a
     | szerepet a beváltás adná ki — egy olyan pillanatban, amikor a meghívó már nincs is
     | a képben.
     */
    $workspace = Workspace::factory()->create();
    $admin = memberUser($workspace, 'admin');

    Auth::login($admin);

    app(WorkspaceContext::class)->runFor($workspace, function () {
        expect(fn () => app(InviteMember::class)('uj@pelda.hu', ['owner']))
            ->toThrow(AuthorizationException::class);

        $invitation = app(InviteMember::class)('Uj.Kollega@Pelda.HU', ['editor']);

        // A CÍM NORMALIZÁLT: a beváltás pontos egyezést néz.
        expect((string) $invitation->email)->toBe('uj.kollega@pelda.hu');
        expect($invitation->roles())->toBe(['editor']);
    });

    expect(AuditLog::query()->where('action', 'member.invited')->exists())->toBeTrue();
});

it('a meghívó CSAK VERIFIKÁLT címre váltható be, és CSAK az érintett elfogadásával', function () {
    /*
     | Két külön szabály, két külön kár:
     |
     | 1. Verifikálatlan címre beváltva bárki, aki a ventSSO-ban egy IDEGEN címmel
     |    regisztrál, belépne a meghívott workspace-be (pre-registration takeover).
     | 2. Automatikus beváltással egy idegen szervezet PII-t szerezne valakiről (név, cím,
     |    utolsó belépés a tagok-képernyőjükön) pusztán attól, hogy beírta a címét.
     */
    $workspace = Workspace::factory()->create();
    $owner = memberUser($workspace, 'owner');

    Auth::login($owner);
    app(WorkspaceContext::class)->runFor($workspace, fn () => app(InviteMember::class)('uj@pelda.hu', ['editor']));

    $invited = User::create([
        'sso_issuer' => 'https://sso.teszt.invalid',
        'sso_subject' => 'nem-verifikalt',
        'name' => 'Idegen',
        'email' => 'uj@pelda.hu',
        'locale' => 'hu',
    ]);

    $invitation = WorkspaceInvitation::query()->withoutGlobalScopes()->where('email', 'uj@pelda.hu')->firstOrFail();
    $redeem = app(RedeemInvitations::class);

    // VERIFIKÁLATLAN cím: se listázás, se elfogadás.
    expect($redeem->openFor($invited))->toHaveCount(0)
        ->and($redeem->accept($invited, $invitation))->toBeFalse();

    $invited->forceFill(['email_verified_at' => now()])->save();

    // VERIFIKÁLVA: a meghívó LÁTSZIK, de a puszta bejelentkezés nem ad tagságot.
    expect($redeem->openFor($invited))->toHaveCount(1)
        ->and(WorkspaceMember::query()->where('user_id', $invited->getKey())->exists())->toBeFalse();

    $this->actingAs($invited)->get('/invitations')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenancy/Invitations')
            ->has('invitations', 1)
            ->where('invitations.0.workspace', (string) $workspace->name)
        );

    $this->actingAs($invited)->post('/invitations/'.$invitation->uuid.'/accept')
        ->assertRedirect('/dashboard');

    $member = WorkspaceMember::query()->where('user_id', $invited->getKey())->firstOrFail();

    expect($member->roles())->toBe(['editor'])
        ->and((int) $member->workspace_id)->toBe($workspace->getKey());

    // A BEVÁLTÁS a MEGHÍVÓ workspace-ének a naplójába kerül — nem a „nincs workspace"
    // gyűjtőbe: a tenant ott keresi, hogyan keletkezett a tagság.
    $audit = AuditLog::query()->where('action', 'member.invitation.redeemed')->firstOrFail();
    expect((int) $audit->workspace_id)->toBe($workspace->getKey());
});

it('az ELUTASÍTÁS törli a meghívót — a meghívottnak joga van nyom nélkül nemet mondani', function () {
    $workspace = Workspace::factory()->create();
    $owner = memberUser($workspace, 'owner');

    Auth::login($owner);
    app(WorkspaceContext::class)->runFor($workspace, fn () => app(InviteMember::class)('nem@pelda.hu', ['analyst']));

    $invited = User::create([
        'sso_issuer' => 'https://sso.teszt.invalid',
        'sso_subject' => 'elutasito',
        'name' => 'Nem kérem',
        'email' => 'nem@pelda.hu',
        'locale' => 'hu',
    ]);

    $invited->forceFill(['email_verified_at' => now()])->save();

    $invitation = WorkspaceInvitation::query()->withoutGlobalScopes()->where('email', 'nem@pelda.hu')->firstOrFail();

    $this->actingAs($invited)->delete('/invitations/'.$invitation->uuid)->assertRedirect();

    expect(WorkspaceInvitation::query()->withoutGlobalScopes()->whereKey($invitation->getKey())->exists())->toBeFalse()
        ->and(WorkspaceMember::query()->where('user_id', $invited->getKey())->exists())->toBeFalse()
        // A TÉNY nem vész el: az audit-sor marad.
        ->and(AuditLog::query()->where('action', 'member.invitation.declined')->exists())->toBeTrue();
});

it('MÁS meghívóját nem lehet elfogadni', function () {
    /*
     | A meghívó uuid-ja nem titok (a meghívó tenant felületén látszik). A hozzárendelést
     | ezért a VERIFIKÁLT CÍM adja, nem az azonosító ismerete.
     */
    $workspace = Workspace::factory()->create();
    $owner = memberUser($workspace, 'owner');

    Auth::login($owner);
    app(WorkspaceContext::class)->runFor($workspace, fn () => app(InviteMember::class)('cimzett@pelda.hu', ['admin']));

    $invitation = WorkspaceInvitation::query()->withoutGlobalScopes()->where('email', 'cimzett@pelda.hu')->firstOrFail();

    $stranger = User::create([
        'sso_issuer' => 'https://sso.teszt.invalid',
        'sso_subject' => 'idegen',
        'name' => 'Idegen',
        'email' => 'masvalaki@pelda.hu',
        'locale' => 'hu',
    ]);

    $stranger->forceFill(['email_verified_at' => now()])->save();

    $this->actingAs($stranger)->from('/invitations')
        ->post('/invitations/'.$invitation->uuid.'/accept')
        ->assertSessionHas('error');

    expect(WorkspaceMember::query()->where('user_id', $stranger->getKey())->exists())->toBeFalse();
});

it('a HOMOGRÁF cím nem vált be idegen meghívót', function () {
    /*
     | A KELVIN SIGN (U+212A) `mb_strtolower` után `k` — a suppression-kulcs (`listKey`)
     | tehát a `bo<KELVIN>@pelda.hu`-t és a `bok@pelda.hu`-t EGYNEK látja. Suppression-nél
     | ez fail-SAFE (több cím tiltódik), belépési jogcímnél viszont fail-OPEN lett volna:
     | egy admin-meghívót egy MÁSIK postafiók tulajdonosa váltott volna be.
     */
    $workspace = Workspace::factory()->create();
    $owner = memberUser($workspace, 'owner');

    Auth::login($owner);
    app(WorkspaceContext::class)->runFor($workspace, fn () => app(InviteMember::class)('bok@pelda.hu', ['admin']));

    $invitation = WorkspaceInvitation::query()->withoutGlobalScopes()->where('email', 'bok@pelda.hu')->firstOrFail();

    $homograph = "bo\u{212A}@pelda.hu";

    $attacker = User::create([
        'sso_issuer' => 'https://sso.teszt.invalid',
        'sso_subject' => 'homograf',
        'name' => 'Támadó',
        'email' => $homograph,
        'locale' => 'hu',
    ]);

    $attacker->forceFill(['email_verified_at' => now()])->save();

    $redeem = app(RedeemInvitations::class);

    expect($redeem->openFor($attacker))->toHaveCount(0)
        ->and($redeem->accept($attacker, $invitation))->toBeFalse();

    // A BELÉPÉSI JOGCÍMET sem adja meg: a `AuthorizeSsoSubject` ugyanazt a kulcsot nézi.
    expect(fn () => app(AuthorizeSsoSubject::class)([
        'iss' => 'https://sso.teszt.invalid',
        'sub' => 'homograf-2',
        'email' => $homograph,
        'email_verified' => true,
    ], []))->toThrow(AuthorizationException::class);

    // …és MEGHÍVÓT sem lehet unicode helyi részű címre írni: nincs tiszta leképezés.
    app(WorkspaceContext::class)->runFor($workspace, function () use ($homograph) {
        expect(fn () => app(InviteMember::class)($homograph, ['analyst']))->toThrow(RuntimeException::class);
    });
});

it('a LEJÁRT meghívó nem vált be, és a MEGLÉVŐ tagságot egy meghívó nem írja át', function () {
    $workspace = Workspace::factory()->create();
    $owner = memberUser($workspace, 'owner');

    Auth::login($owner);

    $expired = app(WorkspaceContext::class)->runFor($workspace, fn () => app(InviteMember::class)('lejart@pelda.hu', ['editor']));
    $expired->forceFill(['expires_at' => now()->subDay()])->save();

    $user = User::create([
        'sso_issuer' => 'https://sso.teszt.invalid',
        'sso_subject' => 'lejart',
        'name' => 'Késői',
        'email' => 'lejart@pelda.hu',
        'locale' => 'hu',
    ]);

    $user->forceFill(['email_verified_at' => now()])->save();

    $redeem = app(RedeemInvitations::class);

    expect($redeem->openFor($user))->toHaveCount(0)
        ->and($redeem->accept($user, $expired->fresh()))->toBeFalse();

    // A MÁR TAG felhasználó szerepeit egy régi meghívó nem rendezheti át — lefelé sem.
    $workspace->members()->create(['user_id' => $user->getKey(), 'roles' => ['admin']]);
    $expired->forceFill(['expires_at' => now()->addDay()])->save();

    $redeem->accept($user, $expired->fresh());

    $member = WorkspaceMember::query()->where('user_id', $user->getKey())->firstOrFail();
    expect($member->roles())->toBe(['admin']);
});

it('a NYITOTT MEGHÍVÓ belépési jogcím — de csak verifikált címmel', function () {
    /*
     | A `AuthorizeSsoSubject` enélkül elutasítaná a meghívottat (nincs tagsága, és a
     | ventSSO-ban nincs `ventmail` szerepe), és a meghívó lejárna anélkül, hogy bárki
     | beválthatta volna.
     */
    $workspace = Workspace::factory()->create();
    $owner = memberUser($workspace, 'owner');

    Auth::login($owner);
    app(WorkspaceContext::class)->runFor($workspace, fn () => app(InviteMember::class)('jogcim@pelda.hu', ['analyst']));

    $authorize = app(AuthorizeSsoSubject::class);

    $verified = ['iss' => 'https://sso.teszt.invalid', 'sub' => 'jogcim', 'email' => 'jogcim@pelda.hu', 'email_verified' => true];
    $unverified = [...$verified, 'email_verified' => false];

    $authorize($verified, []);

    expect(fn () => $authorize($unverified, []))->toThrow(AuthorizationException::class);
    expect(fn () => $authorize([...$verified, 'email' => 'idegen@pelda.hu'], []))->toThrow(AuthorizationException::class);
});

it('a sub-account KÜLÖN workspace, a létrehozó tagságával — és alá nem jön újabb', function () {
    /*
     | A parent-kapcsolat a SZÁMLÁZÁSÉ és az összesítő nézeteké, nem a belépésé: a tagság
     | a ventMail saját táblájából dől el. Enélkül az ügynökség létrehozna egy
     | sub-accountot, amit utána SENKI nem lát.
     */
    $parent = Workspace::factory()->create();
    $owner = memberUser($parent, 'owner');

    Auth::login($owner);

    $child = app(WorkspaceContext::class)->runFor($parent, fn () => app(CreateSubAccount::class)('Ügyfél Kft.'));

    expect($child->getKey())->not->toBe($parent->getKey())
        ->and($child->parent()?->getKey())->toBe($parent->getKey())
        ->and(WorkspaceMember::query()->where('workspace_id', $child->getKey())->where('user_id', $owner->getKey())->exists())->toBeTrue();

    // EGYSZINTŰ hierarchia: a childban a létrehozás elutasít (a DB-trigger is őrzi).
    $ownerInChild = $owner;

    app(WorkspaceContext::class)->runFor($child, function () use ($ownerInChild) {
        Auth::login($ownerInChild);

        expect(fn () => app(CreateSubAccount::class)('Unoka Kft.'))->toThrow(AuthorizationException::class);
    });

    expect(AuditLog::query()->where('action', 'subAccount.created')->exists())->toBeTrue();
});

it('a meghívó visszavonása AUDITÁLT, és a már tag címre nem születik meghívó', function () {
    $workspace = Workspace::factory()->create();
    $owner = memberUser($workspace, 'owner');

    $this->actingAs($owner)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/members')
        ->post('/members/invitations', ['email' => 'valaki@pelda.hu', 'roles' => ['analyst']])
        ->assertRedirect('/members');

    $invitation = app(WorkspaceContext::class)->runFor(
        $workspace,
        fn () => WorkspaceInvitation::query()->where('email', 'valaki@pelda.hu')->firstOrFail()
    );

    // A MÁR TAG cím nem hívható meg újra: a beváltás felülírná a jelenlegi szerepeit.
    $this->actingAs($owner)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/members')
        ->post('/members/invitations', ['email' => $owner->email, 'roles' => ['analyst']])
        ->assertSessionHas('error');

    $this->actingAs($owner)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/members')
        ->delete('/members/invitations/'.$invitation->uuid)
        ->assertRedirect('/members');

    expect(AuditLog::query()->where('action', 'member.invitation.revoked')->exists())->toBeTrue();

    app(WorkspaceContext::class)->runFor($workspace, function () {
        expect(WorkspaceInvitation::query()->where('email', 'valaki@pelda.hu')->exists())->toBeFalse();
    });
});

it('a tagkezelés a member.manage képességhez kötött', function () {
    $workspace = Workspace::factory()->create();
    $analyst = memberUser($workspace, 'analyst');

    $this->actingAs($analyst)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/members')
        ->assertForbidden();

    $this->actingAs($analyst)->withSession(['workspace_uuid' => $workspace->uuid])
        ->post('/members/invitations', ['email' => 'x@pelda.hu', 'roles' => ['analyst']])
        ->assertForbidden();

    $this->actingAs($analyst)->withSession(['workspace_uuid' => $workspace->uuid])
        ->post('/sub-accounts', ['name' => 'Nem lehet'])
        ->assertForbidden();
});

it('az ADMIN nem fokozhatja le és nem távolíthatja el az OWNER-t', function () {
    /*
     | Az invariáns eddig FÉLOLDALAS volt: „nálad erősebbet nem ADHATSZ" — de nálad
     | erősebbet ELVENNI szabad volt. Mérve: az `admin` (akitől a szerep-térkép szándékosan
     | megvonja a `billing.manage`-t és a `workspace.delete`-et) `analyst`-tá fokozta le az
     | egyik `owner`-t, a másikat pedig kitette. Két tulajdonosból az egyik így elveszett —
     | a teljes átvételt csak az utolsó-tulajdonos védelem akadályozta meg, vagyis egy
     | VÉLETLEN, nem szabály.
     */
    $workspace = Workspace::factory()->create();
    $ownerA = memberUser($workspace, 'owner');
    $ownerB = memberUser($workspace, 'owner');
    $admin = memberUser($workspace, 'admin');

    $targetA = WorkspaceMember::query()->where('user_id', $ownerA->getKey())->firstOrFail();
    $targetB = WorkspaceMember::query()->where('user_id', $ownerB->getKey())->firstOrFail();

    $this->actingAs($admin)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/members')
        ->post('/members/'.$targetA->getKey().'/roles', ['roles' => ['analyst']])
        ->assertSessionHas('error');

    expect($targetA->fresh()->roles())->toBe(['owner']);

    $this->actingAs($admin)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/members')
        ->delete('/members/'.$targetB->getKey())
        ->assertSessionHas('error');

    expect(WorkspaceMember::query()->whereKey($targetB->getKey())->exists())->toBeTrue();

    // AZ AZONOS ERŐSSÉGŰ tag viszont kezelhető: az `admin` az `admin`-t módosíthatja.
    $peer = memberUser($workspace, 'admin');
    $peerMember = WorkspaceMember::query()->where('user_id', $peer->getKey())->firstOrFail();

    $this->actingAs($admin)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/members')
        ->post('/members/'.$peerMember->getKey().'/roles', ['roles' => ['analyst']])
        ->assertRedirect('/members');

    expect($peerMember->fresh()->roles())->toBe(['analyst']);
});

it('SENKI nem zárhatja ki magát a felületről', function () {
    /*
     | Eddig csak a Vue sablon rejtette el a gombot — egy `DELETE /members/{sajátId}`
     | átment, és az illető minden hozzáférését elvesztette, önkiszolgáló visszaút nélkül.
     */
    $workspace = Workspace::factory()->create();
    memberUser($workspace, 'owner');
    $admin = memberUser($workspace, 'admin');

    $own = WorkspaceMember::query()->where('user_id', $admin->getKey())->firstOrFail();

    $this->actingAs($admin)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/members')
        ->delete('/members/'.$own->getKey())
        ->assertSessionHas('error');

    expect(WorkspaceMember::query()->whereKey($own->getKey())->exists())->toBeTrue();
});

it('az ELTÁVOLÍTOTT tag NYITOTT meghívói is elesnek', function () {
    /*
     | A meghívó 14 napig él, a tagság visszavonása azonnal — a kettő között rés volt: aki
     | `admin`-t osztott, majd másnap kikerült, annak a döntése még 13 napig hatályba lépett.
     */
    $workspace = Workspace::factory()->create();
    $owner = memberUser($workspace, 'owner');
    $admin = memberUser($workspace, 'admin');

    Auth::login($admin);
    app(WorkspaceContext::class)->runFor($workspace, fn () => app(InviteMember::class)('tole@pelda.hu', ['analyst']));

    $member = WorkspaceMember::query()->where('user_id', $admin->getKey())->firstOrFail();

    $this->actingAs($owner)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/members')
        ->delete('/members/'.$member->getKey())
        ->assertRedirect('/members');

    expect(WorkspaceInvitation::query()->withoutGlobalScopes()->where('email', 'tole@pelda.hu')->exists())->toBeFalse();
});

it('a sub-account TULAJDONOSAI a parent tulajdonosai — nem a létrehozó admin', function () {
    /*
     | A `subAccount.manage` szándékosan az `admin`-é is, az `owner` viszont
     | `billing.manage`-dzsel jár — és a child fogyása a PARENT konszolidált számlájára megy.
     | Ha a létrehozó admin automatikusan owner lenne a childban, az „admin nem költhet"
     | szabály egy al-fiókon keresztül megkerülhető lenne.
     */
    $parent = Workspace::factory()->create();
    $owner = memberUser($parent, 'owner');
    $admin = memberUser($parent, 'admin');

    Auth::login($admin);

    $child = app(WorkspaceContext::class)->runFor($parent, fn () => app(CreateSubAccount::class)('Ügyfél Kft.'));

    $childOwner = WorkspaceMember::query()
        ->where('workspace_id', $child->getKey())
        ->where('user_id', $owner->getKey())
        ->firstOrFail();

    $childAdmin = WorkspaceMember::query()
        ->where('workspace_id', $child->getKey())
        ->where('user_id', $admin->getKey())
        ->firstOrFail();

    expect($childOwner->roles())->toBe(['owner'])
        ->and($childAdmin->roles())->toBe(['admin']);
});

it('az al-fiókok száma PLAFONOS — a fogyás a parent számlájára megy', function () {
    $parent = Workspace::factory()->create();
    $owner = memberUser($parent, 'owner');

    config(['tenancy.sub_accounts.max' => 1]);

    Auth::login($owner);

    app(WorkspaceContext::class)->runFor($parent, function () {
        app(CreateSubAccount::class)('Első');

        expect(fn () => app(CreateSubAccount::class)('Második'))->toThrow(RuntimeException::class);
    });

    expect($parent->children()->count())->toBe(1);
});

it('ISMERETLEN szerep hibaüzenetet ad, nem 500-at', function () {
    /*
     | Az `InvalidArgumentException` NEM leszármazottja a `RuntimeException`-nek: a
     | kontroller korábbi `catch`-e nem fogta meg, és a kérés szabad bemenete (a szerep
     | neve) nyers 500-at adott.
     */
    $workspace = Workspace::factory()->create();
    $owner = memberUser($workspace, 'owner');

    $this->actingAs($owner)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/members')
        ->post('/members/invitations', ['email' => 'valaki@pelda.hu', 'roles' => ['nincs-ilyen-szerep']])
        ->assertRedirect('/members')
        ->assertSessionHas('error');
});

it('a BEVÁLTOTT és LEJÁRT meghívók a retenció után törlődnek', function () {
    /*
     | A meghívó olyan ember címét tárolja, aki lehet, hogy sosem lesz felhasználó, és aki
     | nem járult hozzá semmihez. A tagság keletkezésének bizonyítéka az audit-naplóban
     | marad — ez a tábla takarítható (golden rule 10).
     */
    $workspace = Workspace::factory()->create();
    $owner = memberUser($workspace, 'owner');

    Auth::login($owner);

    [$old, $expired, $open] = app(WorkspaceContext::class)->runFor($workspace, function () {
        $old = app(InviteMember::class)('regi@pelda.hu', ['analyst']);
        $old->forceFill(['accepted_at' => now()->subDays(200)])->save();

        $expired = app(InviteMember::class)('lejart@pelda.hu', ['analyst']);
        $expired->forceFill(['expires_at' => now()->subDays(200)])->save();

        return [$old, $expired, app(InviteMember::class)('friss@pelda.hu', ['analyst'])];
    });

    $this->artisan('tenancy:prune-invitations')->assertSuccessful();

    $remaining = WorkspaceInvitation::query()->withoutGlobalScopes()->pluck('uuid');

    expect($remaining)->toHaveCount(1)
        ->and((string) $remaining->first())->toBe((string) $open->uuid);
});
