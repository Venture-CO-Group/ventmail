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

it('a meghívó CSAK VERIFIKÁLT címre váltódik be', function () {
    /*
     | Enélkül bárki, aki a ventSSO-ban egy idegen címmel regisztrál, belépne a meghívott
     | workspace-be — ez a pre-registration account takeover. Az `email_verified_at` az
     | egyetlen jel, ami a cím BIRTOKLÁSÁT bizonyítja.
     */
    $workspace = Workspace::factory()->create();
    $owner = memberUser($workspace, 'owner');

    Auth::login($owner);
    app(WorkspaceContext::class)->runFor($workspace, fn () => app(InviteMember::class)('uj@pelda.hu', ['editor']));

    $unverified = User::create([
        'sso_issuer' => 'https://sso.teszt.invalid',
        'sso_subject' => 'nem-verifikalt',
        'name' => 'Idegen',
        'email' => 'uj@pelda.hu',
        'locale' => 'hu',
    ]);

    expect(app(RedeemInvitations::class)($unverified))->toBe(0);
    expect(WorkspaceMember::query()->where('user_id', $unverified->getKey())->exists())->toBeFalse();

    $unverified->forceFill(['email_verified_at' => now()])->save();

    expect(app(RedeemInvitations::class)($unverified))->toBe(1);

    $member = WorkspaceMember::query()->where('user_id', $unverified->getKey())->firstOrFail();
    expect($member->roles())->toBe(['editor'])
        ->and((int) $member->workspace_id)->toBe($workspace->getKey());

    // A BEVÁLTÁS a MEGHÍVÓ workspace-ének a naplójába kerül — nem a „nincs workspace"
    // gyűjtőbe: a tenant ott keresi, hogyan keletkezett a tagság.
    $audit = AuditLog::query()->where('action', 'member.invitation.redeemed')->firstOrFail();
    expect((int) $audit->workspace_id)->toBe($workspace->getKey());
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

    expect(app(RedeemInvitations::class)($user))->toBe(0);

    // A MÁR TAG felhasználó szerepeit egy régi meghívó nem rendezheti át — lefelé sem.
    $workspace->members()->create(['user_id' => $user->getKey(), 'roles' => ['admin']]);
    $expired->forceFill(['expires_at' => now()->addDay()])->save();

    app(RedeemInvitations::class)($user);

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
