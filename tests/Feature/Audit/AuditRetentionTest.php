<?php

declare(strict_types=1);

use App\Modules\Audit\Console\PruneAuditLogsCommand;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Audit\Services\KeyedHash;
use App\Modules\Suppression\Models\Suppression;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 | AZ AUDIT-NAPLÓ ÉS A SZEMÉLYES ADAT (C2 security-review · B2).
 |
 | Két, egymást kiegészítő védelem, és mindkettőre azért van szükség, mert a másik nem
 | elég:
 |
 | 1. PSZEUDONIMIZÁLÁS a beírás pillanatában — a MINTÁZOTT PII-re (e-mail-cím). Ez volt a
 |    hiányzó fele: a titok-redakció mezőNÉV-alapú volt, tehát a `context` szabad szöveges
 |    mezőin (`note`, `recipient`) a nyers cím átment egy SOSEM módosítható táblába.
 |
 | 2. RETENCIÓ — a minta nélküli PII-re (támogatói jegyzet, ügy-leírás). Amit nem lehet
 |    felismerni, azt lejáratni lehet. A napló megváltoztathatatlansága a HAMISÍTÁS ellen
 |    véd; a tárolási korlátozás (GDPR 5(1)(e)) egy másik követelmény.
 */

it('a NYERS E-MAIL-CÍM nem kerül az auditba — sem kulcs alatt, sem szabad szövegben', function () {
    $workspace = Workspace::factory()->create();

    app(WorkspaceContext::class)->runFor($workspace, function () {
        app(AuditLogger::class)->log('teszt.pii', null, context: [
            'note' => 'Egyeztettem vele: anna.kovacs@ugyfel-ceg.hu, visszahívást kér.',
            'recipient' => 'Anna Kovacs <anna.kovacs@ugyfel-ceg.hu>',
            'nested' => ['cc' => ['masik@pelda.hu']],
        ]);
    });

    $audit = AuditLog::query()->where('action', 'teszt.pii')->firstOrFail();

    // A `json_encode` az ékezeteket `\uXXXX`-re írja át, ezért a NYERS TÁROLT alakot
    // vizsgáljuk `JSON_UNESCAPED_UNICODE`-dal — különben a magyar szövegre tett állítás
    // az escape-elés miatt bukna el, nem a tartalom miatt.
    $json = (string) json_encode($audit->context, JSON_UNESCAPED_UNICODE);

    expect($json)
        ->not->toContain('anna.kovacs@ugyfel-ceg.hu')
        ->not->toContain('masik@pelda.hu')
        ->toContain('[pii:email:')
        // A KÖRNYEZET MEGMARAD: a jegyzet szövege továbbra is olvasható, csak a cím nem.
        ->toContain('visszahívást kér')
        ->toContain('Anna Kovacs')
        /*
         | A DOMAIN MEGMARAD, és ez nem engedmény: a helyi rész azonosítja a személyt, a
         | domain viszont a DIAGNÓZIS. A minta nem tudja szétválasztani az ÉRINTETT címét a
         | KONFIGURÁCIÓS célcímtől — a teljes csere miatt egy `attacker@evil.com`
         | továbbítási cél csak hash lett volna, tehát egy levél-exfiltráció CÉLJA nem
         | lenne visszakereshető egy törölhetetlen táblában.
         */
        ->toContain('@ugyfel-ceg.hu')
        ->toContain('@pelda.hu');
});

it('a PSZEUDONIM audit-hash ÖSSZEKAPCSOLHATÓ a suppression-sorral', function () {
    /*
     | Miért nem `[redacted]`: a diagnózishoz kell, hogy két sorról meg lehessen állapítani,
     | ugyanarról a címről szólnak-e — és hogy a napló-sor összekapcsolható legyen a
     | tiltással, a nyers cím ismerete nélkül. Ezért UGYANAZ a `KeyedHash::of('email', …)`,
     | amit a `suppressions.email_hash` használ.
     */
    $workspace = Workspace::factory()->create();

    app(WorkspaceContext::class)->runFor($workspace, function () {
        Suppression::create([
            'email' => 'panaszos@pelda.hu',
            'applies_to' => 'marketing',
            'reason' => 'complaint',
            'source' => 'tenant',
        ]);

        app(AuditLogger::class)->log('teszt.hash', null, context: ['note' => 'panaszos@pelda.hu']);
    });

    $audit = AuditLog::query()->where('action', 'teszt.hash')->firstOrFail();
    $suppression = app(WorkspaceContext::class)->runFor($workspace, fn () => Suppression::query()->firstOrFail());

    $prefix = substr(KeyedHash::of('email', 'panaszos@pelda.hu'), 0, 16);

    expect($audit->context['note'])->toBe('[pii:email:'.$prefix.']@pelda.hu')
        ->and((string) $suppression->email_hash)->toStartWith($prefix);
});

it('a LEJÁRT audit-sor törlődik, a FRISS marad', function () {
    $workspace = Workspace::factory()->create();

    $this->travelTo(now()->subDays(800));
    app(WorkspaceContext::class)->runFor($workspace, fn () => app(AuditLogger::class)->log('teszt.regi'));
    $this->travelBack();

    app(WorkspaceContext::class)->runFor($workspace, fn () => app(AuditLogger::class)->log('teszt.friss'));

    $this->artisan('audit:prune')->assertSuccessful();

    expect(AuditLog::query()->where('action', 'teszt.regi')->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'teszt.friss')->count())->toBe(1);
});

it('a TÚL RÖVID retenció MEGÁLLÍTJA a parancsot — nem töröl', function () {
    /*
     | Egy elgépelt `AUDIT_RETENTION_DAYS=1` különben egyetlen ütemezett futással kitörölné
     | a friss naplót — épp azt, ami egy folyamatban lévő incidensnél a legértékesebb.
     */
    $workspace = Workspace::factory()->create();

    $this->travelTo(now()->subDays(800));
    app(WorkspaceContext::class)->runFor($workspace, fn () => app(AuditLogger::class)->log('teszt.regi'));
    $this->travelBack();

    config(['audit.retention_days' => 1]);

    $this->artisan('audit:prune')->assertFailed();

    expect(AuditLog::query()->where('action', 'teszt.regi')->count())->toBe(1)
        ->and(PruneAuditLogsCommand::FLOOR_DAYS)->toBe(365);
});

it('az AUDIT-SOR csak a RETENCIÓS ÚTON törölhető — az adatbázis őrzi', function () {
    /*
     | A PHP-oldali kapu (`AuditLog::whileRetentionPruning`) nem is akar elég lenni: a
     | builder-úton a modell-event nem fut le, ezért a valódi határ a DB-triggerben van.
     | Az pedig a szándék-jelzést ÉS a 365 napos alsó időhatárt EGYMÁSTÓL FÜGGETLENÜL
     | követeli meg.
     */
    $workspace = Workspace::factory()->create();

    $this->travelTo(now()->subDays(800));
    app(WorkspaceContext::class)->runFor($workspace, fn () => app(AuditLogger::class)->log('teszt.regi'));
    $this->travelBack();

    app(WorkspaceContext::class)->runFor($workspace, fn () => app(AuditLogger::class)->log('teszt.friss'));

    // (a) SZÁNDÉK-JELZÉS NÉLKÜL: a régi sor sem törölhető.
    expect(fn () => DB::transaction(fn () => AuditLog::query()->where('action', 'teszt.regi')->delete()))
        ->toThrow(QueryException::class);

    expect(AuditLog::query()->where('action', 'teszt.regi')->count())->toBe(1);

    // (b) SZÁNDÉK-JELZÉSSEL, de az ALSÓ IDŐHATÁRON BELÜL: a friss sor sem törölhető.
    expect(fn () => DB::transaction(fn () => AuditLog::whileRetentionPruning(
        fn () => AuditLog::query()->where('action', 'teszt.friss')->delete()
    )))->toThrow(QueryException::class);

    expect(AuditLog::query()->where('action', 'teszt.friss')->count())->toBe(1);

    // (c) MINDKETTŐVEL: a lejárt sor törlődik.
    DB::transaction(fn () => AuditLog::whileRetentionPruning(
        fn () => AuditLog::query()->where('action', 'teszt.regi')->delete()
    ));

    expect(AuditLog::query()->where('action', 'teszt.regi')->count())->toBe(0);
});

it('az EGYEDI audit-sor törlése a modellen is tiltott', function () {
    $workspace = Workspace::factory()->create();

    $audit = app(WorkspaceContext::class)->runFor($workspace, fn () => app(AuditLogger::class)->log('teszt.modell'));

    expect(fn () => $audit->delete())->toThrow(RuntimeException::class, 'nem törölhető');
});

it('a URL-BE ÁGYAZOTT cím nem viszi el a HOSZTNEVET', function () {
    /*
     | A userinfo-t tartalmazó URL-t (`https://<felhasznalo>@hoszt/utvonal`) a minta
     | e-mail-címként fogja meg. A teljes csere a HOSZTNEVET tüntette el a naplóból
     | (mérve) — vagyis egy webhook átirányítás-vizsgálatnál nem lehetett volna
     | megállapítani, HOVA ment a kérés.
     |
     | A fixtúrában SZÁNDÉKOSAN nincs jelszó a userinfóban: a nevet ÉS jelszót is tartalmazó
     | userinfo Basic Auth hitelesítőnek látszik, és a titok-szkennerek — helyesen —
     | riasztanak rá (a GitGuardian meg is tette). A teszt tárgyához a jelszó nem kell: a
     | pszeudonimizáló ugyanazon az ágon fut, és a hosztnév megtartása ugyanúgy mérhető.
     */
    $workspace = Workspace::factory()->create();

    app(WorkspaceContext::class)->runFor($workspace, function () {
        app(AuditLogger::class)->log('teszt.url', null, context: [
            'endpoint' => 'https://felhasznalo@hook.pelda.hu/webhook',
        ]);
    });

    $audit = AuditLog::query()->where('action', 'teszt.url')->firstOrFail();

    expect($audit->context['endpoint'])
        ->toContain('hook.pelda.hu/webhook')
        ->toContain('[pii:email:');
});

it('a PSZEUDONIM FORMÁTUM RÖGZÍTETT — a korrelálhatóság nem törhet el csendben', function () {
    /*
     | A C2 egy TARTÓS kimeneti formátumot vezetett be egy meglévő primitívre:
     | `[pii:email:` + a `KeyedHash::of('email', DomainNormalizer::listKey($cim))` első 16
     | hex karaktere + `]@domain`. Ez a kompozíció (normalizálás + purpose + csonkolási
     | hossz + a domain megtartása) KORRELÁCIÓ-KULCS a `suppressions.email_hash` felé:
     | ezen múlik, hogy egy napló-sorról meg lehet-e állapítani, ugyanarról a címről szól-e,
     | mint egy tiltás.
     |
     | Egy csendes változás visszamenőleg és VÉGLEGESEN elvágná ezt: az audit-sor nem
     | módosítható, tehát nem lehet újraszámolni. Ezért a teszt MINDEN alkotóelemet külön
     | rögzít — a formátumot, a hosszt, a normalizálást (a KANONIKUS alak literál) és a
     | domain megtartását.
     |
     | (A pepper-derivációt és a purpose-elválasztást a `CryptoVectorsTest` fix vektorai
     | fedik; itt a KOMPOZÍCIÓ a tárgy, nem a HMAC.)
     */
    $workspace = Workspace::factory()->create();

    app(WorkspaceContext::class)->runFor($workspace, function () {
        // A bemenet SZÁNDÉKOSAN nem kanonikus: nagybetűs domain, nagybetűs helyi rész,
        // plus-cím. A normalizálásnak mindezt egy alakra kell hoznia.
        app(AuditLogger::class)->log('teszt.vektor', null, context: [
            'note' => 'Címzett: Anna.Kovacs+szures@Pelda.HU',
        ]);
    });

    $note = (string) AuditLog::query()->where('action', 'teszt.vektor')->firstOrFail()->context['note'];

    // (a) A FORMÁTUM és a HOSSZ: 16 hex, szögletes zárójelben, a domainnel utána —
    //     kisbetűsítve.
    expect($note)->toMatch('/^Címzett: \[pii:email:[0-9a-f]{16}\]@pelda\.hu$/');

    // (b) A NORMALIZÁLÁS és a PURPOSE: a hash a KANONIKUS teljes címből készül. Ha a
    //     `listKey()` vagy a purpose elcsúszik, a két oldal szétválik.
    $expected = substr(KeyedHash::of('email', 'anna.kovacs+szures@pelda.hu'), 0, 16);

    expect($note)->toBe('Címzett: [pii:email:'.$expected.']@pelda.hu');

    // (c) A KORRELÁLHATÓSÁG: ugyanez a prefix a suppression-sor `email_hash`-ében.
    $suppression = app(WorkspaceContext::class)->runFor($workspace, fn () => Suppression::create([
        'email' => 'Anna.Kovacs+szures@Pelda.HU',
        'applies_to' => 'marketing',
        'reason' => 'manual',
        'source' => 'tenant',
    ]));

    expect((string) $suppression->email_hash)->toStartWith($expected);
});
