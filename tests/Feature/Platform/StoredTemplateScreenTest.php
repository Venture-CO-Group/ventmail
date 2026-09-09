<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\StoredTemplates\Models\StoredTemplate;
use App\Modules\StoredTemplates\Models\StoredTemplateVersion;
use App\Modules\StoredTemplates\Services\VersionDiff;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 | M4-6 · TÁROLT SABLONOK + VERZIÓ-ÖSSZEHASONLÍTÁS.
 |
 | A verziók MEGVÁLTOZTATHATATLANOK, tehát a felület nem szerkeszt: azt mutatja meg, mi
 | változott, és melyik verzió ÉLŐ. Az aktiválás eddig csak API-hívással volt lehetséges —
 | egy elrontott verzió visszavonásához éles incidens közben a fejlesztőt kellett keresni.
 */

function storedTemplateUser(Workspace $workspace, string $role = 'developer'): User
{
    $user = User::create([
        'sso_issuer' => 'https://sso.teszt.invalid',
        'sso_subject' => 'sablon-'.uniqid(),
        'name' => 'Fejlesztő',
        'email' => 'sablon-'.uniqid().'@pelda.hu',
        'locale' => 'hu',
    ]);

    $workspace->members()->create(['user_id' => $user->getKey(), 'roles' => [$role]]);

    return $user;
}

function seedStoredTemplate(Workspace $workspace): StoredTemplate
{
    return app(WorkspaceContext::class)->runFor($workspace, function (): StoredTemplate {
        $template = StoredTemplate::create(['name' => 'szamla', 'description' => 'Számla-értesítő']);

        StoredTemplateVersion::create([
            'stored_template_id' => $template->getKey(),
            'version' => 1,
            'body' => "Kedves {{ nev }}!\nA számla elkészült.\nÜdv",
            'is_active' => true,
        ]);

        StoredTemplateVersion::create([
            'stored_template_id' => $template->getKey(),
            'version' => 2,
            'body' => "Kedves {{ nev }}!\nA számlád elkészült.\nLetöltheted itt.\nÜdv",
            'is_active' => false,
        ]);

        return $template;
    });
}

it('a diff a KÉT LEGUTÓBBI verziót veti össze magától', function () {
    /*
     | Üres összehasonlítással indulni azt jelentené, hogy a felhasználó minden
     | látogatáskor kézzel választja ki ugyanazt a két verziót.
     */
    $workspace = Workspace::factory()->create();
    $user = storedTemplateUser($workspace);
    seedStoredTemplate($workspace);

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/stored-templates')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('StoredTemplates/Index')
            ->where('comparison.left', 1)
            ->where('comparison.right', 2)
            ->where('comparison.truncated', false)
            // A MÓDOSÍTOTT sor egy törlés + egy beszúrás, az új sor pedig beszúrás.
            ->where('comparison.lines', fn ($lines) => collect($lines)->where('type', 'added')->count() === 2
                && collect($lines)->where('type', 'removed')->count() === 1
                && collect($lines)->where('type', 'same')->count() === 2)
        );
});

it('az AKTIVÁLÁS átkapcsolja az élő verziót — pontosan egy marad aktív', function () {
    /*
     | Aktív verzió nélkül minden `template=` küldés 400-cal bukik; kettőnél pedig nem
     | lehetne megmondani, melyik ment ki. A kettő közti átmenet ezért egy tranzakció.
     */
    $workspace = Workspace::factory()->create();
    $user = storedTemplateUser($workspace);
    $template = seedStoredTemplate($workspace);

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/stored-templates')
        ->post('/stored-templates/'.$template->uuid.'/versions/2/activate')
        ->assertRedirect('/stored-templates');

    app(WorkspaceContext::class)->runFor($workspace, function () use ($template) {
        $active = StoredTemplateVersion::query()
            ->where('stored_template_id', $template->getKey())
            ->where('is_active', true)
            ->get();

        expect($active)->toHaveCount(1)
            ->and((int) $active->first()->version)->toBe(2);
    });
});

it('a TÚL NAGY diff megmondja, hogy nem futott le — nem üres eredményt ad', function () {
    /*
     | Egy csendben üres összehasonlítás azt jelentené, hogy nem változott semmi. A
     | korlát azért van, mert az LCS-tábla O(n·m) memóriát kér.
     */
    $diff = app(VersionDiff::class);

    $huge = implode("\n", array_fill(0, VersionDiff::MAX_LINES + 1, 'sor'));

    $result = $diff->compare($huge, $huge);

    expect($result['truncated'])->toBeTrue()
        ->and($result['lines'])->toBe([]);
});

it('a diff a SORSZÁMOKAT is megadja, hogy a két oldal összeolvasható legyen', function () {
    $result = app(VersionDiff::class)->compare("egy\nketto\nharom", "egy\nkettő\nharom");

    expect($result['truncated'])->toBeFalse();

    $types = array_column($result['lines'], 'type');

    expect($types)->toBe(['same', 'removed', 'added', 'same']);

    $removed = collect($result['lines'])->firstWhere('type', 'removed');
    $added = collect($result['lines'])->firstWhere('type', 'added');

    expect($removed['left'])->toBe(2)
        ->and($removed['right'])->toBeNull()
        ->and($added['right'])->toBe(2)
        ->and($added['left'])->toBeNull();
});

it('a tárolt sablonok felülete a storedTemplate.manage képességhez kötött', function () {
    /*
     | A tartalmi szerepeké a block editor; ez a FEJLESZTŐ szerződése — egy aktív verzió
     | átkapcsolása egy már működő integráció kimenetét változtatja meg.
     */
    $workspace = Workspace::factory()->create();
    $editor = storedTemplateUser($workspace, 'editor');

    $this->actingAs($editor)->withSession(['workspace_uuid' => $workspace->uuid])
        ->get('/stored-templates')
        ->assertForbidden();
});

it('IDEGEN workspace sablonja nem aktiválható', function () {
    $mine = Workspace::factory()->create();
    $theirs = Workspace::factory()->create();

    $user = storedTemplateUser($mine);
    $foreign = seedStoredTemplate($theirs);

    $this->actingAs($user)->withSession(['workspace_uuid' => $mine->uuid])
        ->post('/stored-templates/'.$foreign->uuid.'/versions/2/activate')
        ->assertNotFound();

    app(WorkspaceContext::class)->runFor($theirs, function () use ($foreign) {
        expect((int) $foreign->activeVersion()->version)->toBe(1);
    });
});
