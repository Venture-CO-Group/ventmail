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

it('a NAGY sablon KÖZÖS ELEJE-VÉGE levágódik — a tábla csak az eltérésre épül', function () {
    /*
     | Két egymást követő sablon-verzió néhány sorban tér el. Levágás nélkül a teljes
     | fájlra épült volna az `O(n·m)` tábla — egy 5000 soros sablonnál ez több száz
     | megabájt, akkor is, ha EGYETLEN sor változott.
     */
    $lines = array_map(fn (int $i) => "sor {$i}", range(1, 5000));

    $before = implode("\n", $lines);

    $lines[2500] = 'MÁS SOR';
    $after = implode("\n", $lines);

    $result = app(VersionDiff::class)->compare($before, $after);

    expect($result['truncated'])->toBeFalse();

    $types = array_count_values(array_column($result['lines'], 'type'));

    expect($types['added'] ?? 0)->toBe(1)
        ->and($types['removed'] ?? 0)->toBe(1)
        // A VÁLTOZATLAN sorok NEM mennek ki mind: a kimenet a változás környezete plusz
        // egy összevont „kihagyva" jelzés. Enélkül 5000 sor érkezett volna Inertia-propként.
        ->and(count($result['lines']))->toBeLessThan(20)
        ->and($types['skipped'] ?? 0)->toBeGreaterThan(0);
});

it('a diff a SZABAD MEMÓRIÁHOZ igazodik — a korláton belül fut, fölötte megmondja', function () {
    /*
     | AZ ELSŐ VÁLTOZAT ITT BUKOTT EL, és a teszt nem vette észre: a korlát 4000 sor volt,
     | az éles `memory_limit` viszont 128 MB (a teszt-futásé 512 MB — `phpunit.xml`). Mérve:
     | 2200 sornál FATAL. A verziólistás oldal MAGÁTÓL diffel, tehát a sablon verziólistája
     | és az „Aktiválás" gomb is elérhetetlen lett volna — pont a visszaállítási út.
     |
     | A teszt a SZABAD MEMÓRIÁT szűkíti le arra, amennyi egy éles FPM-workernek marad, és
     | nem egy fix `memory_limit`-et állít be: a PHPUnit soros futásban 130 MB fölött jár,
     | tehát a `ini_set('memory_limit', '128M')` maga bukna el — a mérés helyett a
     | mérőeszköz. Az érvényes állítás így is ugyanaz: a korláton BELÜLI legrosszabb eset
     | lefut (ha nem férne bele, ez FATAL lenne), a korlát FÖLÖTTI pedig „nem futott le"
     | választ ad, nem üres diffet és nem halált.
     */
    $headroom = 100 * 1024 * 1024;
    $original = ini_get('memory_limit');

    ini_set('memory_limit', (string) (memory_get_usage(true) + $headroom));

    try {
        $diff = app(VersionDiff::class);
        $limit = $diff->regionLimit();

        expect($limit)->toBeGreaterThan(0)->toBeLessThanOrEqual(VersionDiff::MAX_REGION_LINES);

        // A KORLÁT ALATTI legrosszabb eset: két TELJESEN eltérő szakasz, közös prefix és
        // suffix nélkül — itt a levágás nem segít, a táblát tényleg fel kell építeni.
        $before = implode("\n", array_map(fn (int $i) => "bal {$i}", range(1, $limit)));
        $after = implode("\n", array_map(fn (int $i) => "jobb {$i}", range(1, $limit)));

        expect($diff->compare($before, $after)['truncated'])->toBeFalse();

        // A KORLÁT FÖLÖTT megmondja, hogy nem futott le — nem üres diffet ad (az azt
        // jelentené, hogy nem változott semmi), és nem is hal meg.
        $tooBig = implode("\n", array_map(fn (int $i) => "bal {$i}", range(1, $limit + 1)));
        $tooBigOther = implode("\n", array_map(fn (int $i) => "jobb {$i}", range(1, $limit + 1)));

        $overflow = $diff->compare($tooBig, $tooBigOther);

        expect($overflow['truncated'])->toBeTrue()
            ->and($overflow['lines'])->toBe([]);
    } finally {
        ini_set('memory_limit', (string) $original);
    }
});

it('a HATALMAS sablon sem viszi el a processzt — a levágott rész SOSEM épül fel', function () {
    /*
     | EZ AZ A HIÁNY, AMIT A SECURITY-REVIEW MÉRT KI az első javításban.
     |
     | A `regionLimit()` a KÖZÉPSŐ szakaszt fogja — egy 300 000 soros sablonpárnál viszont a
     | középső szakasz EGY sor, tehát a guard sosem sült el, a levágott prefix és suffix
     | pedig teljes egészében felépült a kimeneti tömbben. Mérve: NÉMA PROCESSZ-HALÁL
     | (nem elkapható fatal — FPM alatt 502 és worker-halál), miközben az oldal
     | PARAMÉTER NÉLKÜL, magától diffel.
     |
     | A teszt a szabad memóriát szűkíti, és azt állítja: a hatalmas bemenet vagy lefut
     | KEVÉS kimeneti sorral, vagy `truncated` — de sosem eszi meg a memóriát.
     */
    $original = ini_get('memory_limit');

    ini_set('memory_limit', (string) (memory_get_usage(true) + 100 * 1024 * 1024));

    try {
        $diff = app(VersionDiff::class);

        // A BEMENETI PLAFON a fájl MÉRETÉRE néz, nem a különbségre.
        expect($diff->inputLimit())->toBeGreaterThan(0)
            ->toBeLessThanOrEqual(VersionDiff::MAX_INPUT_LINES);

        $huge = $diff->inputLimit() + 1;

        $lines = array_fill(0, $huge, '<tr><td>x</td></tr>');
        $before = implode("\n", $lines);
        $lines[(int) ($huge / 2)] = '<tr><td>MÁS</td></tr>';
        $after = implode("\n", $lines);

        $result = $diff->compare($before, $after);

        // A PLAFON FÖLÖTT: megmondja, hogy nem futott le.
        expect($result['truncated'])->toBeTrue();

        // A PLAFON ALATT, DE HATALMAS FÁJLLAL: lefut, és KEVÉS sort ad — a memória a
        // VÁLTOZÁSOK számával skálázik, nem a fájl méretével.
        $big = (int) ($diff->inputLimit() * 0.9);
        $lines = array_fill(0, $big, '<tr><td>x</td></tr>');
        $before = implode("\n", $lines);
        $lines[(int) ($big / 2)] = '<tr><td>MÁS</td></tr>';
        $after = implode("\n", $lines);

        $result = $diff->compare($before, $after);

        expect($result['truncated'])->toBeFalse()
            ->and($result['identical'])->toBeFalse()
            ->and(count($result['lines']))->toBeLessThan(20);

        // A KIHAGYOTT SZAKASZOK SORTARTOMÁNYT is hoznak: enélkül a sorszám-oszlop
        // magyarázat nélkül ugrana.
        $skipped = collect($result['lines'])->firstWhere('type', 'skipped');

        expect($skipped)->not->toBeNull()
            ->and($skipped['left'])->toBe(1)
            ->and($skipped['skipped'])->toBeGreaterThan(1000);
    } finally {
        ini_set('memory_limit', (string) $original);
    }
});

it('AZONOS verziókra kimondja, hogy nincs eltérés — nem egy „kihagyva" sort ad', function () {
    /*
     | A kontextusos kimenet két azonos verzióra EGYETLEN összevont sor lenne, és a
     | felhasználónak abból kellene kikövetkeztetnie, hogy nem változott semmi. Ezen a
     | képernyőn ez pont a rossz irányba téveszt.
     */
    $body = implode("\n", array_map(fn (int $i) => "sor {$i}", range(1, 500)));

    $result = app(VersionDiff::class)->compare($body, $body);

    expect($result['identical'])->toBeTrue()
        ->and($result['truncated'])->toBeFalse()
        ->and($result['lines'])->toBe([]);
});

it('a KORLÁT a szabad memóriával mozog — szűkebb környezetben szigorúbb', function () {
    /*
     | Ez a lényeg: a korlát nem beégetett szám. A régi 4000-es konstans a teszt 512 MB-ján
     | átment, éles 128 MB-on viszont FATAL volt — a szám ugyanaz, a környezet más.
     */
    $original = ini_get('memory_limit');
    $diff = app(VersionDiff::class);

    try {
        ini_set('memory_limit', (string) (memory_get_usage(true) + 400 * 1024 * 1024));
        $wide = $diff->regionLimit();

        ini_set('memory_limit', (string) (memory_get_usage(true) + 40 * 1024 * 1024));
        $narrow = $diff->regionLimit();

        expect($narrow)->toBeLessThan($wide);
    } finally {
        ini_set('memory_limit', (string) $original);
    }
});

it('a felület a NEM LÉTEZŐ verzióra hibát ad, nem sikerüzenetet', function () {
    /*
     | A csendes visszatérés mellett a felület „A verzió aktív lett" üzenetet írt — éles
     | incidens közben az üzemeltető azt hitte volna, hogy visszaállt, miközben semmi nem
     | történt.
     */
    $workspace = Workspace::factory()->create();
    $user = storedTemplateUser($workspace);
    $template = seedStoredTemplate($workspace);

    $this->actingAs($user)->withSession(['workspace_uuid' => $workspace->uuid])
        ->from('/stored-templates')
        ->post('/stored-templates/'.$template->uuid.'/versions/99/activate')
        ->assertRedirect('/stored-templates')
        ->assertSessionHas('error');

    app(WorkspaceContext::class)->runFor($workspace, function () use ($template) {
        expect((int) $template->activeVersion()->version)->toBe(1);
    });
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
