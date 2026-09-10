<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Actions\AuthorizeSsoSubject;
use App\Modules\Tenancy\Actions\AssignWorkspaceRoles;
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

it('a LEFOKOZOTT tag már nem kiosztható meghívói is elesnek', function () {
    /*
     | A `RemoveMember` revokálta az eltávolított tag meghívóit, a lefokozás viszont nem —
     | pedig az az olcsóbb, gyakoribb reakció. Mérve: `admin` A `admin`-meghívót ír, az
     | owner `analyst`-ra fokozza A-t, a meghívó nyitva marad `["admin"]` szereppel, a
     | meghívott elfogadja — és `admin` tagságot kap attól, akitől ezt a jogot épp elvették.
     */
    $workspace = Workspace::factory()->create();
    $owner = memberUser($workspace, 'owner');
    $admin = memberUser($workspace, 'admin');

    Auth::login($admin);

    app(WorkspaceContext::class)->runFor($workspace, function () {
        app(InviteMember::class)('eros@pelda.hu', ['admin']);
        // A GYENGE meghívó megmarad: egy lefokozás nem vonja vissza azt, amit az új
        // szerep is fedez.
        app(InviteMember::class)('gyenge@pelda.hu', ['analyst']);
    });

    $member = WorkspaceMember::query()->where('user_id', $admin->getKey())->firstOrFail();

    $this->actingAs($owner)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/members')
        ->post('/members/'.$member->getKey().'/roles', ['roles' => ['analyst']])
        ->assertRedirect('/members');

    $open = WorkspaceInvitation::query()->withoutGlobalScopes()->pluck('email')->all();

    expect($open)->toBe(['gyenge@pelda.hu']);
});

it('a PARENT tulajdonosi köre lekövetődik az al-fiókokra', function () {
    /*
     | A `CreateSubAccount` a létrehozáskori állapotot MÁSOLJA — az pillanatkép. Mérve, mit
     | jelentett önmagában: a childot létrehozó owner távozása után a child tagsága
     | változatlan maradt, az ÚJ tulajdonosnak pedig NULLA tagsága lett benne — a child ott
     | maradt az al-fiók-listán, a parent számlájára fogyott, de a jelenlegi tulajdonos be
     | sem tudott lépni. Élesebben: a távozó kolléga megtartotta az owner-jogot minden
     | általa létrehozott al-fiókon.
     */
    $parent = Workspace::factory()->create();
    $founder = memberUser($parent, 'owner');
    $successor = memberUser($parent, 'admin');

    Auth::login($founder);

    $child = app(WorkspaceContext::class)->runFor($parent, fn () => app(CreateSubAccount::class)('Ügyfél Kft.'));

    // AZ UTÓD OWNER LESZ a parentben → owner lesz a childban is.
    $successorMember = WorkspaceMember::query()->where('user_id', $successor->getKey())->firstOrFail();

    $this->actingAs($founder)->withSession(['workspace_uuid' => $parent->uuid])
        ->from('/members')
        ->post('/members/'.$successorMember->getKey().'/roles', ['roles' => ['owner']])
        ->assertRedirect('/members');

    $inChild = WorkspaceMember::query()
        ->where('workspace_id', $child->getKey())
        ->where('user_id', $successor->getKey())
        ->firstOrFail();

    expect($inChild->roles())->toBe(['owner']);

    // AZ ALAPÍTÓ TÁVOZIK a parentből → a child owner-jogát is elveszíti.
    $founderMember = WorkspaceMember::query()
        ->where('workspace_id', $parent->getKey())
        ->where('user_id', $founder->getKey())
        ->firstOrFail();

    $this->actingAs($successor)->withSession(['workspace_uuid' => $parent->uuid])
        ->from('/members')
        ->delete('/members/'.$founderMember->getKey())
        ->assertRedirect('/members');

    expect(WorkspaceMember::query()
        ->where('workspace_id', $child->getKey())
        ->where('user_id', $founder->getKey())
        ->exists())->toBeFalse();

    // A CHILD NEM MARAD GAZDÁTLAN.
    expect(WorkspaceMember::query()->where('workspace_id', $child->getKey())->count())->toBeGreaterThan(0);
});

it('a parent-oldali admin NEM viheti el a child SAJÁT tulajdonosát', function () {
    /*
     | EZ AZ A RÉS, AMIT A SECURITY-REVIEW AZ ELSŐ TÜKRÖZÉS-VÁLTOZATBAN MÉRT KI.
     |
     | Az `assertTargetNotStronger()` a PARENTBEN mér, a tükrözés mellékhatása viszont a
     | CHILDBAN csapódik le — ahol a célpont erősebb lehet az aktornál. Mérve: `admin` A a
     | parentben eltávolítja `U`-t (aki ott csak `editor`, tehát a törlés jogos), `U`
     | viszont a CHILDBAN `owner` — és a válogatás nélküli visszavonás a child tulajdonosát
     | is elvitte: 0 tag, 0 tulajdonos. Ugyanaz a jogosultsági inverzió, áthelyezve.
     |
     | A childban KÜLÖN kiosztott tagság emberi döntés (nincs `source` jelölése), ahhoz a
     | parent felől nem nyúlunk.
     */
    $parent = Workspace::factory()->create();
    $owner = memberUser($parent, 'owner');
    $admin = memberUser($parent, 'admin');
    $editor = memberUser($parent, 'editor');

    Auth::login($owner);

    $child = app(WorkspaceContext::class)->runFor($parent, fn () => app(CreateSubAccount::class)('Ügyfél Kft.'));

    // A CHILDBAN az editor KÜLÖN kap tulajdonosi jogot — ez a child döntése.
    $childOwn = WorkspaceMember::create([
        'workspace_id' => $child->getKey(),
        'user_id' => $editor->getKey(),
        'roles' => ['owner'],
    ]);

    $parentMember = WorkspaceMember::query()
        ->where('workspace_id', $parent->getKey())
        ->where('user_id', $editor->getKey())
        ->firstOrFail();

    $this->actingAs($admin)->withSession(['workspace_uuid' => $parent->uuid])
        ->from('/members')
        ->delete('/members/'.$parentMember->getKey())
        ->assertRedirect('/members');

    // A PARENTBŐL kikerült…
    expect(WorkspaceMember::query()->whereKey($parentMember->getKey())->exists())->toBeFalse()
        // …a CHILDBAN viszont érintetlen maradt.
        ->and($childOwn->fresh()->roles())->toBe(['owner']);
});

it('a TÜKRÖZÖTT child-tulajdonosság visszavonása AUDITÁLT, a CHILD naplójába', function () {
    /*
     | Golden rule 11. A tükrözés jogot oszt és von vissza; ha nyom nélkül tenné, az
     | al-fiók naplójában egy tulajdonos „csak úgy" megjelenne vagy eltűnne — a saját
     | mércénk szerint ez blokkoló.
     */
    $parent = Workspace::factory()->create();
    $founder = memberUser($parent, 'owner');
    $successor = memberUser($parent, 'owner');

    Auth::login($founder);

    $child = app(WorkspaceContext::class)->runFor($parent, fn () => app(CreateSubAccount::class)('Ügyfél Kft.'));

    $founderMember = WorkspaceMember::query()
        ->where('workspace_id', $parent->getKey())
        ->where('user_id', $founder->getKey())
        ->firstOrFail();

    $this->actingAs($successor)->withSession(['workspace_uuid' => $parent->uuid])
        ->from('/members')
        ->delete('/members/'.$founderMember->getKey())
        ->assertRedirect('/members');

    // A CHILD naplójában ott a sor, a PARENT-re hivatkozó indoklással.
    $audit = AuditLog::query()
        ->where('workspace_id', $child->getKey())
        ->where('action', 'member.removed')
        ->get();

    expect($audit)->toHaveCount(1)
        ->and($audit->first()->context['reason'] ?? null)->toBe('parent_owner_sync');

    // A CHILD nem maradt tulajdonos nélkül: az utód tükrözött tagsága ott van.
    expect(WorkspaceMember::query()
        ->where('workspace_id', $child->getKey())
        ->where('user_id', $successor->getKey())
        ->exists())->toBeTrue();
});

it('a CHILD utolsó tulajdonosát a tükrözés sem viszi el', function () {
    /*
     | Ugyanaz az invariáns, mint a parentben — csak a childra kiértékelve. Enélkül a
     | parent-oldali tulajdonos-csere gazdátlan al-fiókot hagyott volna: a child ott van a
     | listán, a parent számlájára fogy, de senki nem tud belépni.
     */
    $parent = Workspace::factory()->create();
    $founder = memberUser($parent, 'owner');
    $second = memberUser($parent, 'owner');

    Auth::login($founder);

    $child = app(WorkspaceContext::class)->runFor($parent, fn () => app(CreateSubAccount::class)('Ügyfél Kft.'));

    // A MÁSODIK tulajdonos tükrözött tagságát a childból kézzel kivesszük: innentől a
    // childnak EGYETLEN (tükrözött) tulajdonosa van.
    WorkspaceMember::query()
        ->where('workspace_id', $child->getKey())
        ->where('user_id', $second->getKey())
        ->delete();

    $founderMember = WorkspaceMember::query()
        ->where('workspace_id', $parent->getKey())
        ->where('user_id', $founder->getKey())
        ->firstOrFail();

    $this->actingAs($second)->withSession(['workspace_uuid' => $parent->uuid])
        ->from('/members')
        ->delete('/members/'.$founderMember->getKey())
        ->assertRedirect('/members');

    // A childban MARAD a tulajdonos — a parent-oldali döntés nem tehet gazdátlanná egy
    // al-fiókot.
    expect(WorkspaceMember::query()
        ->where('workspace_id', $child->getKey())
        ->get()
        ->contains(fn (WorkspaceMember $member) => in_array('owner', $member->roles(), true)))->toBeTrue();
});

it('a BACKFILL pótolja a régi al-fiókok hiányzó tulajdonosait — és csak hozzáad', function () {
    /*
     | A tükrözés csak a JÖVŐBELI szerep-változásokra fut, tehát a korábban létrehozott
     | al-fiókokban csak a létrehozó a tulajdonos. Ha ő kikerült a parentből, a child
     | gazdátlan — platform-admin nélkül javíthatatlan.
     */
    $parent = Workspace::factory()->create();
    $owner = memberUser($parent, 'owner');
    $child = Workspace::factory()->create();

    $parent->children()->attach($child->getKey());

    // A CHILDNAK van egy SAJÁT tagja, akihez a parancs nem nyúl.
    $localAdmin = memberUser($child, 'admin');

    // ALAPBÓL SZÁRAZ FUTÁS: a parancs jogot oszt, tehát írni külön kell kérni.
    $this->artisan('tenancy:sync-sub-account-owners')->assertSuccessful();

    expect(WorkspaceMember::query()
        ->where('workspace_id', $child->getKey())
        ->where('user_id', $owner->getKey())
        ->exists())->toBeFalse();

    // ÍRÁSHOZ FELELŐS is kell: egy jogot osztó művelet nem maradhat név nélkül a naplóban.
    $this->artisan('tenancy:sync-sub-account-owners --force')->assertFailed();

    $this->artisan('tenancy:sync-sub-account-owners --force --actor=uzemeltetes@pelda.hu')
        ->assertSuccessful();

    $mirrored = WorkspaceMember::query()
        ->where('workspace_id', $child->getKey())
        ->where('user_id', $owner->getKey())
        ->firstOrFail();

    expect($mirrored->roles())->toBe(['owner'])
        ->and($mirrored->hasMirroredOwnership())->toBeTrue()
        // A SAJÁT tagság érintetlen.
        ->and(WorkspaceMember::query()
            ->where('workspace_id', $child->getKey())
            ->where('user_id', $localAdmin->getKey())
            ->firstOrFail()
            ->roles())->toBe(['admin']);
});

it('a TÜKRÖZÖTT tulajdonosi jog a MEGLÉVŐ soron is jelölve van — különben visszavonhatatlan', function () {
    /*
     | FAIL-OPEN ÁG, a security-review mérése alapján.
     |
     | Aki a childban emberi döntésből `editor` volt, majd a parentben `owner` lett, a
     | régi kódban JELÖLETLEN tulajdonosi jogot kapott (a jelölés csak az INSERT ágon volt).
     | A visszavonás ezért azonnal visszatért: a parentből eltávolított kolléga `owner`
     | maradt a child workspace-ben — `billing.manage`, `workspace.delete`, `member.manage`
     | egy olyan fiókon, ami a parent számlájára fogy. Offboarding után teljes hozzáférés.
     */
    $parent = Workspace::factory()->create();
    $owner = memberUser($parent, 'owner');
    $colleague = memberUser($parent, 'editor');

    Auth::login($owner);

    $child = app(WorkspaceContext::class)->runFor($parent, fn () => app(CreateSubAccount::class)('Ügyfél Kft.'));

    // A KOLLÉGA a childban EMBERI DÖNTÉSBŐL `editor` (nem tükrözés).
    WorkspaceMember::create([
        'workspace_id' => $child->getKey(),
        'user_id' => $colleague->getKey(),
        'roles' => ['editor'],
    ]);

    $colleagueMember = WorkspaceMember::query()
        ->where('workspace_id', $parent->getKey())
        ->where('user_id', $colleague->getKey())
        ->firstOrFail();

    // ELŐLÉPTETÉS a parentben → a tükrözés a MEGLÉVŐ sorhoz adja hozzá az ownert.
    $this->actingAs($owner)->withSession(['workspace_uuid' => $parent->uuid])
        ->from('/members')
        ->post('/members/'.$colleagueMember->getKey().'/roles', ['roles' => ['owner']])
        ->assertRedirect('/members');

    $inChild = WorkspaceMember::query()
        ->where('workspace_id', $child->getKey())
        ->where('user_id', $colleague->getKey())
        ->firstOrFail();

    expect($inChild->roles())->toContain('owner')
        ->and($inChild->hasMirroredOwnership())->toBeTrue();

    // ELTÁVOLÍTÁS a parentből → a TÜKRÖZÖTT tulajdonosi jog elesik, az EMBERI DÖNTÉSBŐL
    // kapott `editor` viszont marad.
    $this->actingAs($owner)->withSession(['workspace_uuid' => $parent->uuid])
        ->from('/members')
        ->delete('/members/'.$colleagueMember->getKey())
        ->assertRedirect('/members');

    $after = WorkspaceMember::query()
        ->where('workspace_id', $child->getKey())
        ->where('user_id', $colleague->getKey())
        ->firstOrFail();

    expect($after->roles())->toBe(['editor'])
        ->and($after->hasMirroredOwnership())->toBeFalse();
});

it('a CHILD SAJÁT döntése felülírja a tükrözés-jelölést', function () {
    /*
     | A MÁSIK IRÁNY, ugyanabból a mérésből: a jelölés „ragadós" volt. A child tulajdonosa
     | KIFEJEZETTEN `owner`-t adott valakinek egy korábban tükrözött soron — a sor mégis
     | tükrözésnek számított, és a parent-oldali lefokozás leszedte. A jelölés nem azt
     | tárolja, hogyan keletkezett a sor, hanem hogy a TULAJDONOSI JOGOT ki adta utoljára.
     */
    $parent = Workspace::factory()->create();
    $owner = memberUser($parent, 'owner');
    $colleague = memberUser($parent, 'owner');

    Auth::login($owner);

    $child = app(WorkspaceContext::class)->runFor($parent, fn () => app(CreateSubAccount::class)('Ügyfél Kft.'));

    // A CHILDBAN a kolléga tükrözött ownerként van bent…
    $inChild = WorkspaceMember::query()
        ->where('workspace_id', $child->getKey())
        ->where('user_id', $colleague->getKey())
        ->firstOrFail();

    expect($inChild->hasMirroredOwnership())->toBeTrue();

    // …majd a CHILD tulajdonosa KIFEJEZETTEN kiosztja neki ugyanezt a szerepet.
    app(WorkspaceContext::class)->runFor($child, function () use ($owner, $colleague) {
        Auth::login($owner);

        app(AssignWorkspaceRoles::class)($colleague, ['owner']);
    });

    expect($inChild->fresh()->hasMirroredOwnership())->toBeFalse();

    // A PARENT-OLDALI lefokozás ezt már nem veheti el: a childban emberi döntés született.
    $parentMember = WorkspaceMember::query()
        ->where('workspace_id', $parent->getKey())
        ->where('user_id', $colleague->getKey())
        ->firstOrFail();

    $this->actingAs($owner)->withSession(['workspace_uuid' => $parent->uuid])
        ->from('/members')
        ->post('/members/'.$parentMember->getKey().'/roles', ['roles' => ['analyst']])
        ->assertRedirect('/members');

    expect($inChild->fresh()->roles())->toBe(['owner']);
});
