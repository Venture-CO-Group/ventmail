<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Billing\Enums\Feature;
use App\Modules\Billing\Services\EntitlementChecker;
use App\Modules\Consent\Services\ConsentRecorder;
use App\Modules\Deliverability\Events\BlocklistStateChanged;
use App\Modules\Domains\Models\Domain;
use App\Modules\Identity\Services\PlatformAdminSync;
use App\Modules\IpPools\Models\IpAddress;
use App\Modules\IpPools\Models\IpPool;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Sending\Contracts\SendingDriver;
use App\Modules\SuperAdmin\Jobs\DeliverOnCallAlert;
use App\Modules\SuperAdmin\Models\ImpersonationSession;
use App\Modules\SuperAdmin\Models\PlanOverride;
use App\Modules\SuperAdmin\Services\AuditSearch;
use App\Modules\SuperAdmin\Services\ClusterHealth;
use App\Modules\SuperAdmin\Services\GlobalSuppressions;
use App\Modules\SuperAdmin\Services\Impersonation;
use App\Modules\SuperAdmin\Services\IpSupervision;
use App\Modules\SuperAdmin\Services\OnCallAlerts;
use App\Modules\SuperAdmin\Services\PlanOverrides;
use App\Modules\Suppression\Enums\SuppressionReason;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Models\WorkspaceMember;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Modules\Webhooks\Services\WebhookSigner;
use App\Modules\Webhooks\Services\WebhookUrlGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/*
 | C2 · 81–83. kritérium — platform-admin, impersonation, plan-override.
 |
 | A packet TÉTJE: a platform-admin TENANTEKEN ÁTNYÚLVA dolgozik. Ez a rendszer legerősebb
 | jogosultsága, és épp ezért itt a legdrágább a hiba — egy elfelejtett ellenőrzés nem egy
 | tenant adatát adja ki, hanem MINDEGYIKET.
 |
 | Ezért minden művelet auditált, az impersonation időkorlátos és ÍRÁSRA TILTOTT, és a
 | jogosultság forrása a ventSSO claim, nem a mi adatbázisunk — egy SSO-oldali visszavonás
 | nálunk is érvényesül.
 */

function platformAdmin(): User
{
    $user = User::create([
        'sso_issuer' => 'https://sso.teszt.invalid',
        'sso_subject' => 'admin-'.uniqid(),
        'name' => 'Platform Admin',
        'email' => 'admin@ventmail.app',
    ]);

    $user->forceFill(['platform_admin' => true])->save();

    return $user;
}

function tenantUser(Workspace $workspace, string $role = 'owner'): User
{
    $user = User::create([
        'sso_issuer' => 'https://sso.teszt.invalid',
        'sso_subject' => 'tenant-'.uniqid(),
        'name' => 'Tenant',
        'email' => 'tenant-'.uniqid().'@pelda.hu',
    ]);

    WorkspaceMember::create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $user->getKey(),
        'roles' => [$role],
    ]);

    return $user;
}

/**
 * Egy IP a tenant SAJÁT pooljában.
 *
 * A platform-admin teszteknek SZÁNDÉKOSAN nincs workspace-kontextusa (a platform-admin
 * tenanteken átnyúlva dolgozik) — a `IpPool` viszont tenant- vagy platform-szintű entitás,
 * és kontextus nélkül csak `::onPlatform()`-mal írható. A fixtúrát ezért egy workspace
 * kontextusában hozzuk létre, ahogy a valóságban is a tenant pooljáról van szó.
 *
 * @param  array<string, mixed>  $attributes
 */
function tenantIp(array $attributes = []): IpAddress
{
    $workspace = Workspace::factory()->create();

    return app(WorkspaceContext::class)->runFor($workspace, function () use ($attributes): IpAddress {
        $pool = IpPool::create([
            'name' => $attributes['pool'] ?? 'Saját',
            'type' => 'dedicated',
        ]);

        return IpAddress::create(array_merge([
            'ip_pool_id' => $pool->getKey(),
            'address' => $attributes['address'] ?? '203.0.113.1',
            'hostname' => 'ip.pelda.hu',
        ], array_diff_key($attributes, ['pool' => true, 'address' => true])));
    });
}

/**
 * Az ügyeleti csatorna teszt-titka — SZÁNDÉKOSAN nem beégetett literál.
 *
 * A gitleaks `generic-api-key` szabálya a titok-alakú, beégetett sztringre helyesen
 * riaszt (mérve: 4 találat), és egy teszt-fixtúra nem érdemel allowlist-bejegyzést a
 * `.gitleaks.toml`-ban: az allowlist a KAT-vektoroknak van fenntartva, ahol a LITERÁL
 * maga a teszt tárgya. Itt a tesztnek csak egy NEM ÜRES titokra van szüksége — a konkrét
 * érték érdektelen —, ezért számoljuk.
 */
function oncallSecret(): string
{
    return 'teszt-ugyelet-'.str_repeat('ab', 12);
}

/*
 | 81. A SUPER-ADMIN FELÜLET NEM TENANT-FELÜLET
 */

it('a `platform.admin` képesség CSAK a platform-adminnak van meg', function () {
    $workspace = Workspace::factory()->create();

    $admin = platformAdmin();
    $owner = tenantUser($workspace, 'owner');

    expect(Gate::forUser($admin)->allows('platform.admin'))->toBeTrue()
        // A TENANT `owner` a saját workspace-én mindenható — a PLATFORMON nem.
        ->and(Gate::forUser($owner)->allows('platform.admin'))->toBeFalse();
});

it('a platform-admin jogosultság a ventSSO CLAIMJÉBŐL jön, nem a saját adatbázisunkból', function () {
    /*
     | Ha a jogosultság csak nálunk élne, egy ventSSO-oldali visszavonás nálunk NEM
     | érvényesülne: a leszerelt kollégának másnap is lenne platform-hozzáférése. Az oszlop
     | CACHE, nem forrás — a bejelentkezés újraszámolja.
     */
    $user = platformAdmin();

    expect($user->platform_admin)->toBeTrue();

    // A CLAIM ELTŰNÉSE elveszi a jogot.
    app(PlatformAdminSync::class)->sync($user, ['resource_access' => []]);

    expect($user->fresh()->platform_admin)->toBeFalse()
        ->and(Gate::forUser($user->fresh())->allows('platform.admin'))->toBeFalse();
});

/*
 | 82. AUDITÁLT IMPERSONATION
 */

it('az IMPERSONATION auditált, IDŐKORLÁTOS, és a TENANT is látja', function () {
    $workspace = Workspace::factory()->create();
    $admin = platformAdmin();

    $session = app(Impersonation::class)->start($admin, $workspace, 'SUP-123: a kliens nem látja a kampányát');

    expect($session->expires_at->isFuture())->toBeTrue()
        ->and($session->expires_at->diffInMinutes(now()))->toBeLessThanOrEqual(Impersonation::MAX_MINUTES);

    // AUDITÁLT, és a TENANT audit-naplójában is: nem a mi belső ügyünk, hanem az övé.
    $audit = AuditLog::query()->where('action', 'platform.impersonation_started')->firstOrFail();

    expect((int) $audit->workspace_id)->toBe($workspace->getKey())
        ->and(json_encode($audit->context))->toContain('SUP-123');

    // A LEZÁRÁS is auditált.
    app(Impersonation::class)->end($session);

    expect($session->fresh()->ended_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'platform.impersonation_ended')->count())->toBe(1);
});

it('az IMPERSONÁLT munkamenetben az ÍRÁS TILOS — a támogatás LÁT, nem módosít', function () {
    /*
     | Ez a kritérium magja. Egy támogatási munkamenet célja a megértés: „mit lát a
     | kliens?" Az írás jogával viszont a platform-admin a tenant NEVÉBEN módosíthatna —
     | és az audit-nyom az ő nevén lenne, miközben a tenant azt látná, hogy „valaki"
     | átírta a kampányát.
     |
     | A TESZT VALÓDI ÍRÁST PRÓBÁL. Az első változat csak azt állította, hogy
     | `isReadOnly()` igazat ad — a security-review pedig megmérte, hogy közben a
     | `Domain::create()` és a `Suppression::delete()` is SIKERÜLT: a metódust semmi nem
     | kérdezte meg. Egy állítás nem védelem.
     */
    $workspace = Workspace::factory()->create();
    $admin = platformAdmin();

    $session = app(Impersonation::class)->start($admin, $workspace, 'SUP-1: nézzük meg');

    expect(app(Impersonation::class)->isReadOnly($admin))->toBeTrue();

    // A BEJELENTKEZETT admin nevében: pontosan ez a valós helyzet.
    $this->actingAs($admin);

    app(WorkspaceContext::class)->runFor($workspace, function () {
        expect(fn () => Domain::create([
            'name' => 'tamogatas-irt.hu', 'status' => 'verified', 'verified_at' => now(),
        ]))->toThrow(RuntimeException::class, 'ÍRÁS TILOS');
    });

    expect(app(WorkspaceContext::class)->runFor($workspace, fn () => Domain::query()->count()))->toBe(0);

    // A LEZÁRÁS UTÁN már nem: a platform-admin a SAJÁT nevében dolgozhat.
    app(Impersonation::class)->end($session);

    expect(app(Impersonation::class)->isReadOnly($admin))->toBeFalse();

    $domain = app(WorkspaceContext::class)->runFor($workspace, fn () => Domain::create([
        'name' => 'lezaras-utan.hu', 'status' => 'verified', 'verified_at' => now(),
    ]));

    expect($domain->exists)->toBeTrue();
});

it('a PLATFORM-SZINTŰ írás aktív nézet alatt is megy — a tilalom a TENANT adatára szól', function () {
    /*
     | A tilalom első változata a modell TRAITJÉRE illesztett, és a `Suppression` a
     | `BelongsToWorkspaceOrPlatform`-ot használja — mérve tehát a GLOBÁLIS tiltás
     | felvétele is elbukott, kezeletlen `RuntimeException`-nel (HTTP 500).
     |
     | Ez pont a 84. kritérium fő munkafolyamata: a támogatás megnyitja a tenant nézetét,
     | MEGLÁTJA a spam-csapdát, és letiltja MINDENKINÉL. A tilalom a tenant NEVÉBEN
     | történő írásra szól, nem a támogatás saját, platform-szintű műveletére.
     */
    $workspace = Workspace::factory()->create();
    $admin = platformAdmin();

    app(Impersonation::class)->start($admin, $workspace, 'SUP-45: a kliens panaszol');

    $this->actingAs($admin);

    $suppression = app(WorkspaceContext::class)->runFor($workspace, fn () => app(GlobalSuppressions::class)
        ->add($admin, 'csapda3@spamtrap.example', SuppressionReason::Policy, 'SUP-45: minden tenantnál panasz'));

    expect($suppression->workspace_id)->toBeNull()
        ->and($suppression->isPlatformWide())->toBeTrue();

    // A FELOLDÁS is: ugyanaz a művelet, ugyanaz a jog.
    app(GlobalSuppressions::class)->remove($admin, $suppression, 'SUP-45: tévedés volt');

    expect(Suppression::query()->withoutGlobalScopes()->whereNull('workspace_id')->count())->toBe(0);

    // …a TENANT adata viszont továbbra is tiltott.
    app(WorkspaceContext::class)->runFor($workspace, function () {
        expect(fn () => Suppression::create([
            'email' => 'tenanté@pelda.hu',
            'applies_to' => 'marketing',
            'reason' => 'manual',
            'source' => 'tenant',
        ]))->toThrow(RuntimeException::class, 'ÍRÁS TILOS');
    });
});

it('az IMPERSONATION tényleg ÁTVESZI a tenant nézetét — tagság nélkül is', function () {
    /*
     | A 82. kritérium másik fele: „átveheti egy tenant nézetét". A security-review
     | megmérte, hogy a `start()` SEMMILYEN hozzáférést nem adott — a `ResolveWorkspace`
     | kizárólag a `workspace_members` tagságból oldotta fel a kontextust, tehát a
     | platform-admin a `start()` után is 403-at kapott minden tenant-route-on. A funkció
     | nem hibás volt, hanem nem működött.
     |
     | A nézet-átvétel és az írás-tilalom SZÁNDÉKOSAN egy packetben van: aki a nézetet
     | bekötné a tilalom nélkül, az adná a legerősebb jogot a legkisebb korláttal.
     */
    Route::middleware(['web', 'auth', 'workspace'])->get('/teszt/impersonalt', function (WorkspaceContext $context) {
        return ['workspace' => $context->workspace()->uuid];
    });

    $workspace = Workspace::factory()->create();
    $admin = platformAdmin();

    // TAGSÁG NÉLKÜL, impersonation ELŐTT: nincs mit feloldani — 403.
    $this->actingAs($admin)->get('/teszt/impersonalt')->assertForbidden();

    $session = app(Impersonation::class)->start($admin, $workspace, 'SUP-4: nézzük a kampányt');

    $this->actingAs($admin)->get('/teszt/impersonalt')
        ->assertOk()
        ->assertJson(['workspace' => $workspace->uuid]);

    // A LEZÁRÁS VISSZAVESZI: a nézet nem ragad be.
    app(Impersonation::class)->end($session);

    $this->actingAs($admin)->get('/teszt/impersonalt')->assertForbidden();

    /*
     | AZ EXPLICIT VÁLTÁS ERŐSEBB a nézetnél: nyitott munkamenet alatt is elérheti a SAJÁT
     | workspace-ét — különben a nyitva hagyott nézet üzemeltetői zsákutca lenne. Az
     | ÍRÁS-TILALOM ettől nem lazul: az a MUNKAMENET létezésén áll, nem a kontextuson.
     */
    $sajat = Workspace::factory()->create();
    $sajat->members()->create(['user_id' => $admin->getKey(), 'roles' => ['owner']]);

    app(Impersonation::class)->start($admin, $workspace, 'SUP-4b: újra megnyitva');

    $this->actingAs($admin)->get('/teszt/impersonalt?workspace='.$sajat->uuid)
        ->assertOk()
        ->assertJson(['workspace' => $sajat->uuid]);

    expect(app(Impersonation::class)->isReadOnly($admin))->toBeTrue();

    // …az IMPERSONÁLT workspace-re viszont EXPLICIT váltással sincs tagsága: 403. A nézetet
    // a munkamenet adja, nem a query-paraméter — az nem lehet kiskapu.
    $this->actingAs($admin)->get('/teszt/impersonalt?workspace='.$workspace->uuid)
        ->assertForbidden();
});

it('EGY ADMINNAK EGYSZERRE EGY nyitott munkamenete van — az adatbázis őrzi', function () {
    /*
     | A `start()` lezárja a korábbiakat, de két párhuzamos hívás között nincs zár: mérve
     | két aktív sor maradt, és onnantól sem az `active()`, sem az írás-tilalom nem tudja
     | megmondani, MELYIK tenant nézetében van az admin. A részleges unique index az, ami
     | ezt a versenyhelyzetben is kizárja.
     */
    $workspace = Workspace::factory()->create();
    $admin = platformAdmin();

    $first = app(Impersonation::class)->start($admin, $workspace, 'SUP-5: első');
    app(Impersonation::class)->start($admin, Workspace::factory()->create(), 'SUP-5: második');

    expect(ImpersonationSession::query()->where('user_id', $admin->getKey())->whereNull('ended_at')->count())->toBe(1);

    /*
     | A NYERS beszúrás — a szolgáltatás megkerülésével — az adatbázison bukik el.
     |
     | A `DB::transaction()` SAVEPOINT-ot ad: a trigger/index kivétele Postgresen ABORTÁLJA
     | a tranzakciót (25P02), és utána minden további lekérdezés elbukna — a savepoint
     | nélkül maga a teszt következő sora hasalna el, nem a mért állítás.
     */
    expect(fn () => DB::transaction(fn () => ImpersonationSession::withoutEvents(function () use ($admin, $workspace) {
        $session = new ImpersonationSession([
            'user_id' => $admin->getKey(),
            'reason' => 'párhuzamos',
            'started_at' => now(),
            'expires_at' => now()->addMinutes(5),
        ]);
        $session->workspace_id = $workspace->getKey();
        $session->save();
    })))->toThrow(QueryException::class);

    expect($first->fresh()->ended_at)->not->toBeNull();
});

it('a LEJÁRT impersonation MAGÁTÓL megszűnik — nincs elfelejtett munkamenet', function () {
    // A támogatás percekig néz egy fiókot, nem napokig. Az időkorlát nélkül egy
    // elfelejtett impersonation hetekig élne, és a tenant adatai nyitva állnának.
    $workspace = Workspace::factory()->create();
    $admin = platformAdmin();

    app(Impersonation::class)->start($admin, $workspace, 'SUP-2: gyors nézés');

    expect(app(Impersonation::class)->active($admin))->not->toBeNull();

    $this->travel(Impersonation::MAX_MINUTES + 1)->minutes();

    expect(app(Impersonation::class)->active($admin))->toBeNull()
        // …és az írás-tilalom is megszűnik, mert nincs aktív munkamenet.
        ->and(app(Impersonation::class)->isReadOnly($admin))->toBeFalse();
});

it('az IMPERSONATION INDOKA kötelező', function () {
    // A „csak megnéztem" nem elfogadható indok egy idegen fiók megnyitásához. A támogatási
    // ügy száma az, ami egy későbbi vizsgálatnál megmondja, MIÉRT történt.
    $workspace = Workspace::factory()->create();
    $admin = platformAdmin();

    expect(fn () => app(Impersonation::class)->start($admin, $workspace, '   '))
        ->toThrow(InvalidArgumentException::class, 'INDOK');
});

it('a TENANT-FELHASZNÁLÓ nem indíthat impersonationt', function () {
    $workspace = Workspace::factory()->create();
    $owner = tenantUser($workspace, 'owner');

    expect(fn () => app(Impersonation::class)->start($owner, $workspace, 'SUP-3'))
        ->toThrow(AuthorizationException::class);
});

/*
 | AZ AUDIT-SOR AKTORA — a JOGOSÍTOTT admin, nem a bejelentkezett felhasználó
 */

it('a platform-műveletek AKTORA a gate-elt admin, bejelentkezés nélkül is', function () {
    /*
     | A `AuditLogger` az `Auth::user()`-t vette aktornak, a C2-szolgáltatások viszont
     | explicit `User $admin`-ra kapuznak. Két mért következmény: (1) munkamenet nélkül
     | (konzol, queue-worker) a rendszer LEGERŐSEBB műveletei aktor nélkül kerültek a
     | naplóba; (2) ha más volt bejelentkezve, mint akit a gate engedett, a napló a ROSSZ
     | embert nevezte meg — és az `AuditSearch` `actor_email` szűrője pont ezeket a
     | műveleteket nem találta meg.
     |
     | A packet 35 tesztje közül EGY SEM jelentkezett be, ezért egyik sem fogta meg.
     */
    $workspace = Workspace::factory()->create();
    $admin = platformAdmin();

    // BEJELENTKEZÉS NÉLKÜL (ahogy egy konzol-parancs vagy egy job futna).
    app(Impersonation::class)->start($admin, $workspace, 'SUP-30: konzolból');

    $audit = AuditLog::query()->where('action', 'platform.impersonation_started')->firstOrFail();

    expect($audit->user_id)->toBe($admin->getKey())
        ->and($audit->actor_email)->toBe($admin->email)
        ->and($audit->actor_sso_subject)->toBe($admin->sso_subject);
});

it('MÁS bejelentkezett felhasználó nem lophatja el a platform-művelet nyomát', function () {
    // Ha az aktor az `Auth::user()` lenne, a napló azt nevezné meg, aki éppen be van
    // jelentkezve — nem azt, akire a jogosultság-ellenőrzés ment.
    $workspace = Workspace::factory()->create();
    $admin = platformAdmin();
    $masvalaki = tenantUser($workspace, 'owner');

    $this->actingAs($masvalaki);

    app(PlanOverrides::class)->grant(
        $admin,
        $workspace,
        features: [Feature::Journeys],
        sendQuota: null,
        reason: 'SUP-31: kivétel',
        expiresAt: now()->addDay(),
    );

    $audit = AuditLog::query()->where('action', 'platform.plan_override_granted')->firstOrFail();

    expect($audit->actor_email)->toBe($admin->email)
        ->and($audit->actor_email)->not->toBe($masvalaki->email);
});

it('az ELUTASÍTOTT platform-művelet is nyomot hagy', function () {
    /*
     | A 81. kritérium szerint MINDEN platform-művelet auditált — és egy visszavont admin
     | (vagy egy kíváncsi tenant-owner) próbálkozása épp a napló legérdekesebb sora.
     | Mérve: a sikertelen hívás után NULLA audit-sor volt, tehát a támadás-kísérlet
     | láthatatlan maradt.
     */
    $workspace = Workspace::factory()->create();
    $owner = tenantUser($workspace, 'owner');

    expect(fn () => app(AuditSearch::class)->search($owner))->toThrow(AuthorizationException::class);
    expect(fn () => app(Impersonation::class)->start($owner, $workspace, 'SUP-32'))
        ->toThrow(AuthorizationException::class);

    $denied = AuditLog::query()->where('action', 'platform.denied')->get();

    expect($denied)->toHaveCount(2)
        ->and($denied->pluck('actor_email')->unique()->all())->toBe([$owner->email])
        ->and(json_encode($denied->pluck('context')->all()))
        ->toContain('audit_search')
        ->toContain('impersonation');
});

it('a PLATFORM-SZINTŰ keresés nyoma NEM esik egy véletlen tenant naplójába', function () {
    /*
     | Mérve: a tenanteken átnyúló keresés audit-sora a maradék workspace-kontextusból
     | kapott `workspace_id`-t — vagyis a platform saját „ki keresett mit" nyoma
     | szétszóródott a tenantok naplói között, ahol se nem az ő ügyük, se nem kereshető
     | vissza.
     */
    $workspace = Workspace::factory()->create();
    $admin = platformAdmin();

    app(WorkspaceContext::class)->runFor($workspace, function () use ($admin) {
        app(AuditSearch::class)->search($admin, ['action' => 'platform.']);
    });

    $audit = AuditLog::query()->where('action', 'platform.audit_searched')->firstOrFail();

    expect($audit->workspace_id)->toBeNull();
});

it('az AUDIT-SZŰRŐ nem értelmezi a `like` metakaraktereit', function () {
    // Egy `100%`-ot tartalmazó szűrő különben MINDEN sort adna: a felület azt mutatná,
    // hogy találat van, holott a szűrő nem is illeszkedett.
    $workspace = Workspace::factory()->create();
    $admin = platformAdmin();

    app(Impersonation::class)->start($admin, $workspace, 'SUP-33: legyen mit találni');

    $mind = app(AuditSearch::class)->search($admin);
    $csillag = app(AuditSearch::class)->search($admin, ['action' => '%']);

    expect(count($mind['items']))->toBeGreaterThan(0)
        ->and($csillag['items'])->toBe([]);
});

/*
 | 83. PLAN-OVERRIDE
 */

it('a PLAN-OVERRIDE felülírja a ventPay válaszát — LEJÁRATTAL és auditáltan', function () {
    $workspace = Workspace::factory()->create();
    $admin = platformAdmin();

    // A ventPay szerint NINCS journeys.
    config(['sending.fake_entitlement.features' => []]);

    expect(app(EntitlementChecker::class)->allows($workspace, Feature::Journeys))->toBeFalse();

    app(PlanOverrides::class)->grant(
        $admin,
        $workspace,
        features: [Feature::Journeys],
        sendQuota: 50000,
        reason: 'SUP-9: próbaidő a journey-re',
        expiresAt: now()->addDays(7),
    );

    expect(app(EntitlementChecker::class)->allows($workspace, Feature::Journeys))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'platform.plan_override_granted')->count())->toBe(1);

    // A LEJÁRAT UTÁN MAGÁTÓL megszűnik: az override kivétel, nem állapot.
    $this->travel(8)->days();

    expect(app(EntitlementChecker::class)->allows($workspace, Feature::Journeys))->toBeFalse();
});

it('az OVERRIDE KVÓTÁJA eljut a PreSendGate 4. kapujáig — nem csak a felületre', function () {
    /*
     | A 83. kritérium szó szerint: „A PreSendGate 4. kapuja és az `EntitlementChecker` az
     | override-ot veszi figyelembe."
     |
     | Mérve NEM ez történt: a kapu a NYERS `VentPayClient::remainingSendQuota()`-t hívta,
     | az `EntitlementChecker::remainingQuota()` pedig HÍVÓ NÉLKÜLI halott kód volt
     | (egyetlen grep-találat: a definíció). Vagyis a támogatás megemelte a kvótát,
     | auditálva, a felület azt mondta „megvan" — és a levél mégis `QUOTA_EXCEEDED`-del
     | elakadt. Egy képesség, amit a valódi úton semmi nem hív, nem létezik.
     */
    $workspace = Workspace::factory()->create();
    $admin = platformAdmin();

    // A ventPay szerint a kvóta ELFOGYOTT.
    config(['sending.fake_entitlement.remaining' => 0]);

    app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace) {
        Domain::create(['name' => 'kvota'.$workspace->getKey().'.hu', 'status' => 'verified', 'verified_at' => now()]);

        $result = app(SendMessage::class)([
            'channel' => 'transactional',
            'from_email' => 'no-reply@kvota'.$workspace->getKey().'.hu',
            'to' => ['cimzett@pelda.hu'],
            'subject' => 'Szia',
            'html' => '<p>Szia</p>',
            'idempotency_key' => 'kvota-elott',
        ]);

        expect($result->decision->allowed)->toBeFalse()
            ->and($result->decision->reason)->toBe('QUOTA_EXCEEDED');
    });

    app(PlanOverrides::class)->grant(
        $admin,
        $workspace,
        features: [],
        sendQuota: 500000,
        reason: 'SUP-42: egyszeri kampány-keret',
        expiresAt: now()->addDay(),
    );

    app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace) {
        $result = app(SendMessage::class)([
            'channel' => 'transactional',
            'from_email' => 'no-reply@kvota'.$workspace->getKey().'.hu',
            'to' => ['cimzett@pelda.hu'],
            'subject' => 'Szia',
            'html' => '<p>Szia</p>',
            'idempotency_key' => 'kvota-utan',
        ]);

        expect($result->decision->allowed)->toBeTrue();
    });
});

it('a SUB-ACCOUNT a PARENT kvótáját kapja a kapuban', function () {
    /*
     | A kapu a nyers `$message->workspace_id`-vel kérdezett, tehát a CHILD-ot — a számla
     | viszont a parenté. Egy alszámla így vagy nulla kvótát látott, vagy másik keretet,
     | mint amiért fizetnek. Az override a PARENT-en van, ahogy a csomag is.
     */
    $parent = Workspace::factory()->create();
    $child = Workspace::factory()->create();
    $child->parents()->attach($parent);

    $admin = platformAdmin();

    config(['sending.fake_entitlement.remaining' => 0]);

    app(PlanOverrides::class)->grant(
        $admin,
        $parent,
        features: [],
        sendQuota: 1000,
        reason: 'SUP-43: a parent kerete',
        expiresAt: now()->addDay(),
    );

    app(WorkspaceContext::class)->runFor($child, function () use ($child) {
        Domain::create(['name' => 'child'.$child->getKey().'.hu', 'status' => 'verified', 'verified_at' => now()]);

        $result = app(SendMessage::class)([
            'channel' => 'transactional',
            'from_email' => 'no-reply@child'.$child->getKey().'.hu',
            'to' => ['cimzett@pelda.hu'],
            'subject' => 'Szia',
            'html' => '<p>Szia</p>',
            'idempotency_key' => 'child-kvota',
        ]);

        expect($result->decision->allowed)->toBeTrue();
    });
});

it('a VISSZAVONT override nem élesztheto újra — az adatbázis őrzi', function () {
    /*
     | Mérve: egy tömeges `update(['revoked_at' => null, 'expires_at' => +5 év])` két sort
     | érintett, NULLA audit-sort írt, és az override újra hatott. Ugyanaz a hibaosztály,
     | amit a C1 `Dunning::transition()`-nál is javítottunk: az alkalmazás-szintű
     | állapotgép a builder-úton nem fut le, ezért az invariáns az adatbázisban van.
     */
    $workspace = Workspace::factory()->create();
    $admin = platformAdmin();

    // A ventPay szerint NINCS journeys — így a `true` csak az override-tól jöhet.
    config(['sending.fake_entitlement.features' => []]);

    $override = app(PlanOverrides::class)->grant(
        $admin,
        $workspace,
        features: [Feature::Journeys],
        sendQuota: null,
        reason: 'SUP-44: próbaidő',
        expiresAt: now()->addDays(7),
    );

    app(PlanOverrides::class)->revoke($admin, $override);

    expect(app(EntitlementChecker::class)->allows($workspace, Feature::Journeys))->toBeFalse();

    // A SAVEPOINT itt is kell: a trigger kivétele abortálja a tranzakciót.
    expect(fn () => DB::transaction(fn () => PlanOverride::query()
        ->whereKey($override->getKey())
        ->update(['revoked_at' => null, 'expires_at' => now()->addYears(5)])))
        ->toThrow(QueryException::class);

    expect(app(EntitlementChecker::class)->allows($workspace, Feature::Journeys))->toBeFalse();
});

it('a `created_by` nem hamisítható tömeges hozzárendelésből', function () {
    // A „ki adta" válasza a jogosultság-emelés elszámoltathatósága. Mérve: egy tenant
    // felhasználójának az id-je bekerült az `created_by`-ba egy `new PlanOverride([...])`
    // hívásból — vagyis a napló szerint a tenant adta magának a csomag-emelést.
    $workspace = Workspace::factory()->create();
    $owner = tenantUser($workspace, 'owner');

    expect(fn () => new PlanOverride(['created_by' => $owner->getKey()]))
        ->toThrow(MassAssignmentException::class);
});

it('az OVERRIDE HOZZÁAD, nem helyettesít — az üres lista nem zár be semmit', function () {
    /*
     | Ha az override HELYETTESÍTENÉ a csomagot, egy kvóta-emelés (ami funkciót nem érint)
     | véletlenül ELVENNE minden funkciót. A támogatás egy dolgot akar megváltoztatni, nem
     | mindent.
     */
    $workspace = Workspace::factory()->create();
    $admin = platformAdmin();

    config(['sending.fake_entitlement.features' => ['custom_domain']]);

    app(PlanOverrides::class)->grant(
        $admin,
        $workspace,
        features: [],
        sendQuota: 99999,
        reason: 'SUP-10: csak kvóta',
        expiresAt: now()->addDay(),
    );

    expect(app(EntitlementChecker::class)->allows($workspace, Feature::CustomDomain))->toBeTrue()
        ->and(app(PlanOverrides::class)->sendQuota($workspace))->toBe(99999);
});

it('az OVERRIDE VISSZAVONHATÓ, és a visszavonás is auditált', function () {
    $workspace = Workspace::factory()->create();
    $admin = platformAdmin();

    config(['sending.fake_entitlement.features' => []]);

    $override = app(PlanOverrides::class)->grant(
        $admin,
        $workspace,
        features: [Feature::DedicatedIp],
        sendQuota: null,
        reason: 'SUP-11',
        expiresAt: now()->addDays(30),
    );

    expect(app(EntitlementChecker::class)->allows($workspace, Feature::DedicatedIp))->toBeTrue();

    app(PlanOverrides::class)->revoke($admin, $override);

    expect(app(EntitlementChecker::class)->allows($workspace, Feature::DedicatedIp))->toBeFalse()
        ->and(AuditLog::query()->where('action', 'platform.plan_override_revoked')->count())->toBe(1);
});

it('a SUB-ACCOUNT a PARENT override-ját is megkapja', function () {
    // A parent fizet, tehát a parent csomagja — és a parentre adott kivétel — határozza meg,
    // mit tud a child. Ugyanaz a szabály, mint a C1 `EntitlementChecker`-ében.
    $parent = Workspace::factory()->create();
    $child = Workspace::factory()->create();
    $parent->children()->attach($child, ['permissions' => '{}']);

    $admin = platformAdmin();

    config(['sending.fake_entitlement.features' => []]);

    app(PlanOverrides::class)->grant(
        $admin,
        $parent,
        features: [Feature::Journeys],
        sendQuota: null,
        reason: 'SUP-12',
        expiresAt: now()->addDay(),
    );

    expect(app(EntitlementChecker::class)->allows($child, Feature::Journeys))->toBeTrue();
});

it('a TENANT-FELHASZNÁLÓ nem adhat magának override-ot', function () {
    $workspace = Workspace::factory()->create();
    $owner = tenantUser($workspace, 'owner');

    expect(fn () => app(PlanOverrides::class)->grant(
        $owner,
        $workspace,
        features: [Feature::DedicatedIp],
        sendQuota: 999999,
        reason: 'kell',
        expiresAt: now()->addYear(),
    ))->toThrow(AuthorizationException::class);

    expect(PlanOverride::query()->count())->toBe(0);
});

/*
 | 84. GLOBÁLIS SUPPRESSION
 */

it('a GLOBÁLIS tiltás MINDEN tenantra hat, és a KAPUBAN fog', function () {
    /*
     | Három eset indokolja: egy spam-csapda cím, egy jogi felszólítás, vagy egy visszaélő
     | cím, ami minden tenantnál panaszt generál. Egyik sem EGY tenant ügye — és ha
     | tenantonként kellene felvenni, az első kimaradó tenant vinné a közös IP reputációját.
     */
    $admin = platformAdmin();

    $egyik = Workspace::factory()->create();
    $masik = Workspace::factory()->create();

    app(GlobalSuppressions::class)
        ->add($admin, 'csapda@spamtrap.example', SuppressionReason::Complaint, 'SUP-20: spam-csapda, minden tenantnál panasz');

    // MINDKÉT tenantnál fog — a kapu a platform-szintű tiltást is látja.
    foreach ([$egyik, $masik] as $workspace) {
        app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace) {
            Domain::create([
                'name' => 'w'.$workspace->getKey().'.hu', 'status' => 'verified', 'verified_at' => now(),
            ]);

            $recorder = app(ConsentRecorder::class);
            $recorder->confirm($recorder->request('csapda@spamtrap.example'));

            $result = app(SendMessage::class)([
                'channel' => 'transactional',
                'from_email' => 'no-reply@w'.$workspace->getKey().'.hu',
                'to' => ['csapda@spamtrap.example'],
                'subject' => 'Szia',
                'html' => '<p>Szia</p>',
                'idempotency_key' => 'global-supp-'.$workspace->getKey(),
            ]);

            expect($result->decision->allowed)->toBeFalse()
                ->and($result->decision->reason)->toBe('SUPPRESSED');
        });
    }
});

it('a GLOBÁLIS tiltáshoz és a FELOLDÁSHOZ is INDOKLÁS kell', function () {
    // A feloldás a súlyosabb művelet: attól kezdve MINDEN tenant küldhet arra a címre. Egy
    // fél évvel későbbi reputáció-vizsgálatnál tudni kell, miért került vissza egy
    // spam-csapda a küldhető címek közé.
    $admin = platformAdmin();
    $service = app(GlobalSuppressions::class);

    expect(fn () => $service->add($admin, 'x@pelda.hu', SuppressionReason::Complaint, '  '))
        ->toThrow(InvalidArgumentException::class, 'INDOKLÁS');

    $suppression = $service->add($admin, 'x@pelda.hu', SuppressionReason::Complaint, 'SUP-21: indok');

    expect(fn () => $service->remove($admin, $suppression, ''))
        ->toThrow(InvalidArgumentException::class, 'INDOKLÁS');
});

it('a TENANT-FELHASZNÁLÓ nem adhat GLOBÁLIS tiltást', function () {
    // Enélkül egy tenant `owner` mindenkinek megtilthatná egy versenytárs címét.
    $workspace = Workspace::factory()->create();
    $owner = tenantUser($workspace, 'owner');

    expect(fn () => app(GlobalSuppressions::class)
        ->add($owner, 'aldozat@pelda.hu', SuppressionReason::Manual, 'nem tetszik'))
        ->toThrow(AuthorizationException::class);
});

it('a GLOBÁLIS tiltás TENANT-KONTEXTUSBÓL is GLOBÁLIS — a tenant sora nem tévesztheti meg', function () {
    /*
     | A packet legdrágább hibája volt, és pont a 84. kritérium magját ütötte.
     |
     | A `firstOrCreate` OLVASÓ fele átment a `WorkspaceOrPlatformScope`-on: kontextussal a
     | szűrő `(workspace_id = X OR workspace_id IS NULL)`, tehát a tenant SAJÁT, korábban
     | felvett sora TALÁLAT lett. A szolgáltatás azt adta vissza sikerként, globális sor
     | NEM jött létre (mérve: `workspace_id IS NULL` sorok száma 0), és közben megíródott a
     | `platform.global_suppression_added` audit-sor. Vagyis egy jogi felszólításra felvett
     | cím minden MÁS tenantnál küldhető maradt, a napló szerint elintézve.
     |
     | A hívás a valóságban is kontextusban történik (a támogatás épp egy tenant fiókját
     | nézi), ezért a teszt is így hív.
     */
    $admin = platformAdmin();
    $egyik = Workspace::factory()->create();
    $masik = Workspace::factory()->create();

    // Az EGYIK tenant bounce-kezelője már felvette ugyanazt a címet, SAJÁT sorként.
    app(WorkspaceContext::class)->runFor($egyik, fn () => Suppression::create([
        'email' => 'csapda2@spamtrap.example',
        'applies_to' => 'all',
        'reason' => 'bounce',
        'source' => 'tenant',
    ]));

    $global = app(WorkspaceContext::class)->runFor($egyik, fn () => app(GlobalSuppressions::class)
        ->add($admin, 'csapda2@spamtrap.example', SuppressionReason::Policy, 'SUP-40: jogi felszólítás'));

    expect($global->workspace_id)->toBeNull()
        ->and($global->isPlatformWide())->toBeTrue()
        ->and(Suppression::query()->withoutGlobalScopes()->whereNull('workspace_id')->count())->toBe(1);

    // …és a MÁSIK tenant is látja a tiltást, pedig neki nincs saját sora.
    app(WorkspaceContext::class)->runFor($masik, function () {
        expect(Suppression::query()->where('email', 'csapda2@spamtrap.example')->count())->toBe(1);
    });
});

it('a GLOBÁLIS tiltás FELOLDÁSA tényleg töröl, és a cím újra küldhető', function () {
    /*
     | Az eredeti teszt a `remove()`-ot csak az INDOKLÁS-ellenőrzés miatt hívta, és sosem
     | futtatta le sikeresen: nem állította, hogy a sor törlődött, hogy az audit-sor
     | megvan, és hogy a kapu újra átengedi a címet. A feloldás a súlyosabb művelet —
     | attól kezdve MINDEN tenant küldhet arra a címre.
     */
    $admin = platformAdmin();
    $workspace = Workspace::factory()->create();

    $suppression = app(GlobalSuppressions::class)
        ->add($admin, 'feloldando@pelda.hu', SuppressionReason::Policy, 'SUP-41: felvétel');

    $hash = (string) $suppression->email_hash;

    app(GlobalSuppressions::class)->remove($admin, $suppression, 'SUP-41: a felszólítás visszavonva');

    expect(Suppression::query()->withoutGlobalScopes()->where('email_hash', $hash)->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'platform.global_suppression_removed')->count())->toBe(1);

    // A KAPU újra átengedi: a feloldás nem csak a táblából, a küldésből is látszik.
    app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace) {
        Domain::create(['name' => 'w'.$workspace->getKey().'.hu', 'status' => 'verified', 'verified_at' => now()]);

        $result = app(SendMessage::class)([
            'channel' => 'transactional',
            'from_email' => 'no-reply@w'.$workspace->getKey().'.hu',
            'to' => ['feloldando@pelda.hu'],
            'subject' => 'Szia',
            'html' => '<p>Szia</p>',
            'idempotency_key' => 'feloldas-utan',
        ]);

        expect($result->decision->allowed)->toBeTrue();
    });
});

it('a GLOBÁLIS tiltás NYERS CÍMET nem visz az auditba', function () {
    // Az audit-sor SOHA nem törölhető (GDPR Art. 17): egy nyers cím ott örökre bent
    // maradna, és egy törlési kérés teljesíthetetlen lenne.
    $admin = platformAdmin();

    app(GlobalSuppressions::class)
        ->add($admin, 'titkos@pelda.hu', SuppressionReason::Complaint, 'SUP-22: indok');

    $audit = AuditLog::query()->where('action', 'platform.global_suppression_added')->firstOrFail();

    expect(json_encode([$audit->context, $audit->before, $audit->after]))
        ->not->toContain('titkos@pelda.hu');
});

/*
 | 85. AUDIT-KERESŐ
 */

it('az AUDIT-KERESŐ szűr, lapoz, és a keresés MAGA is auditált', function () {
    /*
     | Az audit-napló a rendszer legérzékenyebb olvasható adata: minden admin-művelet
     | nyoma, minden tenantnál. Aki ezt böngészi, a platform teljes történetét látja — és
     | ennek a hozzáférésnek MAGÁNAK is nyomot kell hagynia. A „ki nézte meg, mit" kérdés
     | egy incidensnél pontosan olyan fontos, mint a „ki írta át".
     */
    $admin = platformAdmin();
    $workspace = Workspace::factory()->create();

    // ZAJ: egy másik tenant művelete.
    $masik = Workspace::factory()->create();
    app(Impersonation::class)->start($admin, $masik, 'SUP-30: másik');
    app(Impersonation::class)->start($admin, $workspace, 'SUP-31: ez kell');

    $search = app(AuditSearch::class);

    $result = $search->search($admin, [
        'action' => 'platform.impersonation_started',
        'workspace_id' => $workspace->getKey(),
    ]);

    expect($result['items'])->toHaveCount(1)
        ->and($result['items'][0]['action'])->toBe('platform.impersonation_started')
        ->and(json_encode($result['items'][0]))->toContain('SUP-31');

    // A KERESÉS auditált — a SZŰRŐKKEL, mert azok mutatják a szándékot.
    $searchAudit = AuditLog::query()->where('action', 'platform.audit_searched')->firstOrFail();

    expect(json_encode($searchAudit->context))->toContain('platform.impersonation_started')
        ->and($searchAudit->context['results'])->toBe(1);
});

it('az AUDIT-KERESŐ KURZOROSAN lapoz — a növekvő tábla nem csúsztatja el', function () {
    // Az audit-tábla folyamatosan nő, és offset-lapozásnál a második oldal lekérésekor a
    // sorok elcsúsznak: a kereső ugyanazt kétszer adná, vagy sosem látna egy sort.
    $admin = platformAdmin();
    $workspace = Workspace::factory()->create();

    foreach (range(1, AuditSearch::PER_PAGE + 5) as $i) {
        app(PlanOverrides::class)->grant(
            $admin, $workspace, [Feature::Journeys], null, 'SUP-'.$i, now()->addDay()
        );
    }

    $search = app(AuditSearch::class);

    $first = $search->search($admin, ['action' => 'platform.plan_override_granted']);

    expect($first['items'])->toHaveCount(AuditSearch::PER_PAGE)
        ->and($first['next_cursor'])->not->toBeNull();

    $second = $search->search($admin, [
        'action' => 'platform.plan_override_granted',
        'cursor' => $first['next_cursor'],
    ]);

    // NINCS ÁTFEDÉS a két lap között.
    $firstIds = array_column($first['items'], 'id');
    $secondIds = array_column($second['items'], 'id');

    expect(array_intersect($firstIds, $secondIds))->toBeEmpty();
});

it('a TENANT-FELHASZNÁLÓ nem kereshet az audit-naplóban', function () {
    // A tenant a SAJÁT naplóját látja a saját felületén; a platform-szintű kereső minden
    // tenant történetét adja — az nem tenant-funkció.
    $workspace = Workspace::factory()->create();
    $owner = tenantUser($workspace, 'owner');

    expect(fn () => app(AuditSearch::class)->search($owner))
        ->toThrow(AuthorizationException::class);
});

/*
 | 86. CLUSTER HEALTH — az ISMERETLEN állapot NEM zöld
 */

it('a CLUSTER HEALTH minden komponenst jelent, és a LEGROSSZABB dönt', function () {
    $admin = platformAdmin();
    $report = app(ClusterHealth::class)->report($admin);

    expect($report['components'])->toHaveKeys([
        // Az `inbound_mx` a 86. kritérium NÉVSZERINTI komponense („MX-fogadó"), ami a
        // packet első körében kimaradt a riportból.
        'database', 'redis', 'queue', 'sending_driver', 'search', 'inbound_mx', 'ip_pool',
    ]);

    // A KOMPONENSEK állapota érvényes érték, nem szabad szöveg.
    // A `toContain` a további argumentumokat KERESETT ÉRTÉKKÉNT érti, nem üzenetként.
    foreach ($report['components'] as $component) {
        expect([
            ClusterHealth::HEALTHY, ClusterHealth::DEGRADED, ClusterHealth::DOWN,
            ClusterHealth::UNKNOWN, ClusterHealth::DISABLED,
        ])->toContain($component['status']);
    }
});

it('a NEM MEGKÉRDEZHETŐ komponens `unknown`, NEM `healthy`', function () {
    /*
     | Ez a kritérium egyetlen fontos szabálya. Egy monitorozó felület legrosszabb hibája
     | nem az, hogy pirosat mutat, amikor minden jó — hanem hogy ZÖLDET mutat, amikor baj
     | van. Ha egy komponens nem válaszol, az `unknown`, és az LÁTSZIK.
     |
     | A `down` ÁLLÍTÁS, az `unknown` a TUDÁS HIÁNYA: a `down` mellett a komponenst kell
     | javítani, az `unknown` mellett a MONITOROZÁST.
     */
    // A KÜLDŐ DRIVER hibára fut: a probe kivételt kap.
    $admin = platformAdmin();

    $this->app->bind(SendingDriver::class, function () {
        throw new RuntimeException('a driver nem elérhető');
    });

    $report = app(ClusterHealth::class)->report($admin);

    expect($report['components']['sending_driver']['status'])->toBe(ClusterHealth::UNKNOWN)
        ->and($report['components']['sending_driver']['status'])->not->toBe(ClusterHealth::HEALTHY)
        // A RÉSZLET a kivétel OSZTÁLYA, nem az üzenete: egy `QueryException` üzenete a
        // teljes SQL-t hordozza behelyettesített értékekkel.
        ->and($report['components']['sending_driver']['detail'])->toContain('RuntimeException')
        // …és az ÖSSZESÍTETT állapot sem lehet zöld, ha egy komponensről nem tudunk.
        ->and($report['status'])->not->toBe(ClusterHealth::HEALTHY);
});

it('a KARANTÉNBA tett IP-k DEGRADÁLT állapotot adnak', function () {
    // Nem `down`: a küldés megy a maradék IP-ken. De az üzemeltetőnek látnia kell, hogy a
    // volumen kevesebb IP-re jut — warmup alatt ez reputáció-vesztés.
    $admin = platformAdmin();

    tenantIp(['address' => '203.0.113.1']);

    $beteg = tenantIp(['address' => '203.0.113.2']);
    $beteg->forceFill(['quarantined_at' => now(), 'status' => 'quarantined'])->save();

    $report = app(ClusterHealth::class)->report($admin);

    expect($report['components']['ip_pool']['status'])->toBe(ClusterHealth::DEGRADED)
        ->and($report['components']['ip_pool']['detail'])->toContain('1/2');
});

/*
 | 87. IP-POOL ÉS WARMUP FELÜGYELET
 */

it('a KARANTÉN és a FELOLDÁS is auditált, és INDOKKAL jár', function () {
    /*
     | A karanténba tétel VÉDELEM (egy beteg IP nem küld tovább); a feloldás viszont
     | ÁLLÍTÁS: „ez az IP megint küldhet". Ha ez indoklás nélkül megtehető lenne, egy
     | blocklistán lévő IP visszakerülhetne a forgalomba anélkül, hogy bárki megnézte
     | volna, lekerült-e a listáról.
     */
    $admin = platformAdmin();

    $ip = tenantIp(['address' => '203.0.113.7']);

    $service = app(IpSupervision::class);

    expect(fn () => $service->quarantine($admin, $ip, ' '))
        ->toThrow(InvalidArgumentException::class, 'INDOK');

    $service->quarantine($admin, $ip, 'SUP-40: Spamhaus SBL találat');

    expect($ip->fresh()->quarantined_at)->not->toBeNull()
        ->and($ip->fresh()->status)->toBe('quarantined')
        ->and(AuditLog::query()->where('action', 'platform.ip_quarantined')->count())->toBe(1);

    expect(fn () => $service->release($admin, $ip->fresh(), ''))
        ->toThrow(InvalidArgumentException::class, 'INDOK');

    $service->release($admin, $ip->fresh(), 'SUP-40: lekerült a listáról, ellenőrizve');

    expect($ip->fresh()->quarantined_at)->toBeNull()
        ->and($ip->fresh()->status)->toBe('active')
        ->and(AuditLog::query()->where('action', 'platform.ip_released')->count())->toBe(1);
});

it('a WARMUP-ÁTTEKINTÉS a KIHASZNÁLTSÁGOT mutatja, nem csak a napot', function () {
    // A puszta „hányadik napon vagyunk" szám félrevezető: egy alacsony forgalmú nap nem
    // hitelesíti az IP-t a fogadóknál. Ami számít, az az ELKÜLDÖTT volumen.
    $admin = platformAdmin();

    tenantIp(['address' => '203.0.113.20', 'pool' => 'Warmup', 'daily_limit' => 1000, 'sent_today' => 250]);

    $overview = app(IpSupervision::class)->warmupOverview($admin);

    $row = collect($overview)->firstWhere('address', '203.0.113.20');

    expect($row['utilisation'])->toBe(25.0)
        ->and($row['daily_limit'])->toBe(1000)
        ->and($row['sent_today'])->toBe(250)
        ->and($row['pool'])->toBe('Warmup');
});

it('a TENANT-FELHASZNÁLÓ nem tehet IP-t karanténba', function () {
    // Egy megosztott poolon több tenant küld: ha egy tenant dönthetne róla, a saját
    // kampányáért kivehetne a forgalomból egy IP-t, amit más tenantok is használnak.
    $workspace = Workspace::factory()->create();
    $owner = tenantUser($workspace, 'owner');

    $ip = tenantIp(['address' => '203.0.113.9']);

    expect(fn () => app(IpSupervision::class)
        ->quarantine($owner, $ip, 'kell nekem'))
        ->toThrow(AuthorizationException::class);
});

/*
 | 88. DELIVERABILITY-RIASZTÁS ÜGYELETI CSATORNÁN
 */

it('a BLOCKLIST-RIASZTÁS az ügyeleti WEBHOOKON megy, ALÁÍRVA — nem e-mailben', function () {
    /*
     | A riasztás tárgya épp az, hogy a KÜLDÉSÜNK nem működik: a saját küldő IP-nk listán
     | van. E-mailben értesíteni az ügyeletet olyan, mint a tűzriadót a leégett épületben
     | kihirdetni. A `PHASE.md` ezt a csatlakozási pontot nevesítette — az esemény megvolt,
     | a HALLGATÓ nem.
     */
    config([
        'superadmin.oncall.url' => 'https://ugyelet.pelda.hu/hook',
        'superadmin.oncall.secret' => oncallSecret(),
    ]);

    Bus::fake();

    BlocklistStateChanged::dispatch(
        '203.0.113.5', 'zen.spamhaus.org', true, 'SBL-találat'
    );

    Bus::assertDispatched(DeliverOnCallAlert::class);
});

it('a LEKERÜLÉS is riasztás — az ügyelet tudja, mikor mehet vissza aludni', function () {
    // A „megoldódott" jelzés nélkül az ügyelet a következő reggeli ellenőrzésig nem tudja,
    // hogy a probléma elmúlt — és fölöslegesen eszkalál.
    config([
        'superadmin.oncall.url' => 'https://ugyelet.pelda.hu/hook',
        'superadmin.oncall.secret' => oncallSecret(),
    ]);

    Bus::fake();

    BlocklistStateChanged::dispatch(
        '203.0.113.5', 'zen.spamhaus.org', false
    );

    Bus::assertDispatched(DeliverOnCallAlert::class);
});

it('a BEÁLLÍTATLAN ügyeleti csatorna HANGOS hiba, nem csendes eldobás', function () {
    /*
     | Egy nem konfigurált ügyeleti csatorna a legrosszabb állapot: a rendszer azt hiszi,
     | riasztott, az ügyelet pedig nem tud semmiről. A naplósor `error` szintű, hogy a
     | log-alapú monitorozás LÁSSA a riasztás elmaradását.
     */
    config(['superadmin.oncall.url' => '', 'superadmin.oncall.secret' => '']);

    Log::spy();

    $raised = app(OnCallAlerts::class)
        ->raise('blocklist.listed', 'A 203.0.113.5 listán van');

    expect($raised)->toBeFalse();

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message) => str_contains($message, 'ÜGYELETI RIASZTÁS ELMARADT'))
        ->once();
});

it('az ÜGYELETI RIASZTÁS nem megy nem-publikus címre, és nem követ átirányítást', function () {
    /*
     | A job docblockja azt állította, hogy „ugyanaz a minta, mint az A3 tenant-webhookjai".
     | Mérve nem az volt: se URL-jóváhagyás, se `allow_redirects => false`, se `verify`.
     | A vektor nem elgépelést igényel — egy legitim, publikus ügyeleti végpont
     | DNS-eltérítése vagy kompromittálása 302-vel a felhő metadata-endpointjára viheti a
     | workerünket, a HMAC-aláírt payloadunkkal a bodyban.
     */
    Http::fake();

    config([
        'superadmin.oncall.url' => 'http://169.254.169.254/latest/meta-data/',
        'superadmin.oncall.secret' => oncallSecret(),
    ]);

    Log::spy();

    // A JOB SZINKRON futtatása: a dispatch-oldal szándékosan nem szűr (a config a mi
    // adatunk), a kézbesítő viszont fail-closed.
    app(DeliverOnCallAlert::class, ['payload' => '{"type":"teszt"}'])
        ->handle(app(WebhookSigner::class), app(WebhookUrlGuard::class));

    Http::assertNothingSent();

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message) => str_contains($message, 'nem publikus https cím'))
        ->once();
});

it('az ÜGYELETI RIASZTÁS aláírása MINDEN kísérletnél újra készül', function () {
    /*
     | A `WebhookSigner::TOLERANCE_SECONDS` 300 s. Ha a timestamp a dispatch pillanatában
     | fagyna be, akkor TORLÓDOTT SOR mellett — vagyis pontosan abban az üzemállapotban,
     | amikor riasztani kell — a fogadó minden kísérletet elavult aláírásként utasítana el.
     | A titok emellett NEM utazik a job-payloadban: a `handle()` a configból olvassa.
     */
    Http::fake(['*' => Http::response('', 200)]);

    config([
        'superadmin.oncall.url' => 'https://ugyelet.pelda.hu/hook',
        'superadmin.oncall.secret' => oncallSecret(),
    ]);

    $job = app(DeliverOnCallAlert::class, ['payload' => '{"type":"teszt"}']);

    // A job SZERIALIZÁLT alakjában nincs se titok, se aláírás, se URL.
    expect(serialize($job))
        ->not->toContain(oncallSecret())
        ->not->toContain('ugyelet.pelda.hu');

    $job->handle(app(WebhookSigner::class), app(WebhookUrlGuard::class));
    $elso = null;

    Http::assertSent(function ($request) use (&$elso) {
        $elso = (int) $request->header('X-VentMail-Timestamp')[0];

        return true;
    });

    // KÉSŐBB (a retry ideje): az új kísérlet ÚJ időbélyeggel és ÚJ aláírással megy.
    $this->travel(240)->seconds();

    $job->handle(app(WebhookSigner::class), app(WebhookUrlGuard::class));

    $bellyegek = [];

    Http::assertSent(function ($request) use (&$bellyegek) {
        $bellyegek[] = (int) $request->header('X-VentMail-Timestamp')[0];

        return true;
    });

    expect(max($bellyegek) - $elso)->toBeGreaterThanOrEqual(240);
});

/*
 | 86. CLUSTER HEALTH — jogosultság és az „ismeretlen"
 */

it('a CLUSTER HEALTH riportot TENANT-FELHASZNÁLÓ nem kérheti le', function () {
    // A riport üzemeltetési topológiát ad ki (sor-mélység, DB-késleltetés, karanténba tett
    // IP-k): egy támadónak pontosan azt, mikor és mivel érdemes próbálkozni. Ez volt az
    // egyetlen C2-szolgáltatás jogosultság-ellenőrzés nélkül.
    $workspace = Workspace::factory()->create();
    $owner = tenantUser($workspace, 'owner');

    expect(fn () => app(ClusterHealth::class)->report($owner))
        ->toThrow(AuthorizationException::class);
});

it('a NEM MÉRHETŐ sor és a NEM BEÁLLÍTOTT kereső `unknown`, nem `healthy`', function () {
    /*
     | Mérve: `queue.default = sync` mellett a `Queue::size()` mindig 0, tehát a próba
     | `healthy`-t adott — worker nélkül, sor nélkül is. A 0 mélység és a „nincs is sor"
     | megkülönböztethetetlen volt: pont a „zöld, mert nem tudjuk".
     |
     | Ugyanez a kereső üres hosztjával: a nem konfigurált és a nem válaszoló komponens
     | mindkettő `degraded` volt, tehát az operátor a keresőt keresi, miközben a config
     | üres.
     */
    $admin = platformAdmin();

    config(['queue.default' => 'sync', 'scout.meilisearch.host' => '']);

    $report = app(ClusterHealth::class)->report($admin);

    expect($report['components']['queue']['status'])->toBe(ClusterHealth::UNKNOWN)
        ->and($report['components']['queue']['status'])->not->toBe(ClusterHealth::HEALTHY)
        ->and($report['components']['search']['status'])->toBe(ClusterHealth::UNKNOWN)
        ->and($report['components']['search']['detail'])->toContain('nincs beállítva')
        ->and($report['status'])->not->toBe(ClusterHealth::HEALTHY);
});

/*
 | 81. A PLATFORM-ADMIN JOGOSULTSÁG MEGADÁSA ÉS ELVÉTELE
 */

it('a platform-admin jog megadása és elvétele AUDITÁLT', function () {
    /*
     | A rendszer legerősebb jogosultsága nem változhat nyom nélkül (golden rule 11).
     | Mérve: hét `sync()` hívás után NULLA audit-sor volt — egy incidens-vizsgálat a
     | ventMail naplójából nem tudta megválaszolni, hogy „ez az ember mikor lett
     | platform-admin".
     */
    $user = User::create([
        'sso_issuer' => 'https://sso.teszt.invalid',
        'sso_subject' => 'jelolt-'.uniqid(),
        'name' => 'Jelölt',
        'email' => 'jelolt@ventmail.app',
    ]);

    $claims = ['resource_access' => ['ventmail-platform' => ['roles' => ['platform_admin']]]];

    app(PlatformAdminSync::class)->sync($user, $claims);

    expect($user->fresh()->isPlatformAdmin())->toBeTrue();

    $granted = AuditLog::query()->where('action', 'platform.admin_granted')->firstOrFail();

    expect($granted->actor_email)->toBe($user->email)
        ->and($granted->workspace_id)->toBeNull();

    // AZ ISMÉTELT bejelentkezés NEM ír új sort: a napló a VÁLTOZÁST őrzi, nem a tényt.
    app(PlatformAdminSync::class)->sync($user->fresh(), $claims);

    expect(AuditLog::query()->where('action', 'platform.admin_granted')->count())->toBe(1);

    // …az ELVÉTEL viszont igen.
    app(PlatformAdminSync::class)->sync($user->fresh(), ['resource_access' => []]);

    expect(AuditLog::query()->where('action', 'platform.admin_revoked')->count())->toBe(1);
});

it('az ÜRES szerepnév és az EGYBEESŐ claim-útvonal NEM ad platform-jogot', function () {
    /*
     | Két mért fail-open sarok:
     |
     | 1. `platform_admin_role = ''` + a claimben egy üres sztring → `sync()` `true`-t
     |    adott. Az üres szerepnév nem „bármi megfelel", hanem konfigurációs hiba.
     | 2. Ha a platform-claim útvonala egybeesik a tenant-szerepekével, akkor egy
     |    WORKSPACE-en belül kiosztott `platform_admin` nevű szerep adna platform-jogot —
     |    vagyis bármelyik tenant-owner adhatna magának mindent. Az egész osztály értelme
     |    ez az elválasztás.
     */
    $user = User::create([
        'sso_issuer' => 'https://sso.teszt.invalid',
        'sso_subject' => 'jelolt2-'.uniqid(),
        'name' => 'Jelölt',
        'email' => 'jelolt2@ventmail.app',
    ]);

    config(['oidc.platform_admin_role' => '']);

    expect(app(PlatformAdminSync::class)->sync($user, [
        'resource_access' => ['ventmail-platform' => ['roles' => ['', '  ']]],
    ]))->toBeFalse();

    config([
        'oidc.platform_admin_role' => 'platform_admin',
        'oidc.platform_admin_claim' => 'resource_access.ventmail.roles',
        'oidc.roles_claim' => 'resource_access.ventmail.roles',
    ]);

    expect(app(PlatformAdminSync::class)->sync($user, [
        'resource_access' => ['ventmail' => ['roles' => ['platform_admin']]],
    ]))->toBeFalse()
        ->and($user->fresh()->isPlatformAdmin())->toBeFalse();
});
