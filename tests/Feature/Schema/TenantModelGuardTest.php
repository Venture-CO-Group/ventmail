<?php

declare(strict_types=1);

use App\Modules\Analytics\Models\CampaignEngagement;
use App\Modules\Analytics\Models\LinkClick;
use App\Modules\Audit\Concerns\Auditable;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Billing\Models\UsageRecord;
use App\Modules\Campaign\Models\CampaignRecipient;
use App\Modules\Consent\Models\ConsentEvent;
use App\Modules\Deliverability\Models\BlocklistCheck;
use App\Modules\Deliverability\Models\DmarcReport;
use App\Modules\Deliverability\Models\DmarcReportRow;
use App\Modules\Deliverability\Models\SeedTest;
use App\Modules\Events\Models\MessageEvent;
use App\Modules\Inbound\Models\InboundAttachment;
use App\Modules\Inbound\Models\InboundMessage;
use App\Modules\Journey\Models\JourneyRun;
use App\Modules\Journey\Models\JourneyRunStep;
use App\Modules\Logs\Models\DomainDailyStat;
use App\Modules\Logs\Models\SmtpTransaction;
use App\Modules\Sending\Models\DeliveryAttempt;
use App\Modules\Sending\Models\Message;
use App\Modules\Sending\Models\PostalMessageRef;
use App\Modules\Sending\Models\PostalWebhookReceipt;
use App\Modules\Sending\Models\RawMimeMessage;
use App\Modules\SuperAdmin\Models\ImpersonationSession;
use App\Modules\SuperAdmin\Models\PlanOverride;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use App\Modules\Tenancy\Concerns\BelongsToWorkspaceOrPlatform;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Models\WorkspaceInvitation;
use App\Modules\Tenancy\Models\WorkspaceMember;
use App\Modules\Validation\Models\ValidationJob;
use App\Modules\Validation\Models\ValidationResult;
use App\Modules\Webhooks\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/*
 | A security-review kérése: minden workspace_id oszlopú táblához tartozó modell HASZNÁLJA
 | a BelongsToWorkspace traitet — különben a tábla kimarad a tenant-izolációból.
 */

/*
 | Dokumentált kivételek — mindkettő a Tenancy/Audit saját infrastruktúrája:
 | - AuditLog: akkor is íródnia kell, amikor NINCS workspace-kontextus (rendszerszintű
 |   művelet), és a super-admin (C2) tenant-független kereséssel dolgozik. Írni csak az
 |   AuditLogger tud, a sor megváltoztathatatlan.
 | - WorkspaceMember: maga a tagsági tábla — ő DÖNTI EL a hozzáférést, nem alá tartozik.
 |   Írása a sanctionált AssignWorkspaceRoles actionön megy.
 */
const TENANT_SCOPE_EXCEPTIONS = [
    AuditLog::class,
    WorkspaceMember::class,
    /*
     | C2 · PLATFORM-SZINTŰ sorok EGY tenantRÓL — nem a tenantÉ.
     |
     | A `workspace_id` itt nem a sor TULAJDONOSÁT jelöli, hanem a MEGNÉZETT, illetve az
     | ÉRINTETT tenantot. A tenant nem indíthat impersonationt magára, és nem adhat magának
     | plan-override-ot — épp az a lényeg, hogy ezek FÖLÖTTE állnak. A
     | `BelongsToWorkspace` tehát nem hogy nem elég, hanem HELYTELEN lenne: a global scope
     | a platform-admin elől rejtené el a saját sorait (nincs workspace-kontextusa), a
     | `creating` hook pedig a kontextusból írná át a `workspace_id`-t — vagyis az admin
     | mindig a SAJÁT (nem létező) workspace-ére nyitna nézetet.
     |
     | A tenant a TÉNYT a saját audit-naplójában látja: az `Impersonation` és a
     | `PlanOverrides` explicit `workspaceId`-vel írja az audit-sort, épp azért, hogy
     | ne a mi belső naplónkban maradjon. A `workspace_id` egyik modellen sem `fillable`.
     */
    ImpersonationSession::class,
    PlanOverride::class,
];

it('minden tenant-tábla modellje használja a BelongsToWorkspace traitet', function () {
    $offenders = [];

    foreach (File::allFiles(app_path('Modules')) as $file) {
        if ($file->getExtension() !== 'php' || ! str_contains($file->getPathname(), '/Models/')) {
            continue;
        }

        $module = basename(dirname($file->getPath()));
        $class = "App\\Modules\\{$module}\\Models\\".$file->getFilenameWithoutExtension();

        if (! class_exists($class)) {
            continue;
        }

        $model = new $class;
        $table = $model->getTable();

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'workspace_id')) {
            continue;
        }

        if (in_array($class, TENANT_SCOPE_EXCEPTIONS, true)) {
            continue;
        }

        $traits = class_uses_recursive($class);

        // Két elfogadott forma: szigorúan tenant-hez kötött, vagy tenant-VAGY-platform
        // (globális suppression, megosztott IP-pool) — utóbbi is fail-closed.
        $scoped = in_array(BelongsToWorkspace::class, $traits, true)
            || in_array(BelongsToWorkspaceOrPlatform::class, $traits, true);

        if (! $scoped) {
            $offenders[] = $class;
        }
    }

    expect($offenders)->toBeEmpty('workspace_id oszlop tenant-izoláció nélkül: '.implode(', ', $offenders));
});

it('minden admin-felületű modell auditált', function () {
    // Az Auditable opt-in — enélkül egy új admin-entitás csendben audit nélkül maradna
    // (golden rule 11). A kivételek dokumentáltak és indokoltak.
    $exceptions = [
        AuditLog::class,        // maga a napló
        MessageEvent::class,   // esemény-adat, nem admin-művelet
        Message::class,       // üzenet-adat; a küldést a gate auditálja
        RawMimeMessage::class, // a beadott levél MAGA (megváltoztathatatlan, nem admin-művelet)
        DeliveryAttempt::class, // a fogadó válasza — gépi adat, nem admin-művelet
        SmtpTransaction::class, // az SMTP-párbeszéd — gépi adat, nem admin-művelet
        WebhookDelivery::class, // egy kimenő kézbesítés sorsa — gépi adat
        Workspace::class,     // a workspace-műveleteket a SuperAdmin (C2) auditálja
        WorkspaceMember::class, // az AssignWorkspaceRoles explicit auditot ír
        // A BEÉRKEZETT levél és a csatolmánya: idegen fél írta, gépi úton érkezett — nem
        // admin-művelet. Az admin-műveletet (a route-szabály szerkesztését) az
        // `InboundRoute` auditálja, és a törlési kérést a `logs:redact-subject`.
        InboundMessage::class,
        InboundAttachment::class,
        // A címellenőrzés EREDMÉNYE gépi adat: a futást az API-hívás indítja (ott van
        // scope-ellenőrzés), a sorok maguk nem admin-műveletek.
        ValidationJob::class,
        ValidationResult::class,
        // A blocklist-ellenőrzés gépi mérés a SAJÁT infrastruktúránkról, nem admin-művelet.
        BlocklistCheck::class,
        // A seed-mérés gépi eredmény: a futást az API indítja (ott van jogosultság-ellenőrzés).
        SeedTest::class,
        // A DMARC-jelentés IDEGEN fél (a fogadó levelezőrendszere) által írt, gépi adat: a
        // betöltés nem admin-művelet, és a sorokat senki nem szerkeszti.
        DmarcReport::class,
        DmarcReportRow::class,
        // A JOURNEY-FUTÁS és a VÉGREHAJTOTT LÉPÉS gépi adat: a motor írja, tickenként,
        // futásonként. Nem admin-művelet — az admin-műveletet (a journey szerkesztését,
        // aktiválását) a `Journey` és a `JourneyStep` auditálja. Soronkénti audit mellett
        // egy tízezres journey lépésenként tízezer törölhetetlen sort írna, a címzettek
        // e-mail-címével együtt.
        JourneyRun::class,
        JourneyRunStep::class,
        // A KAMPÁNY-CÍMZETT gépi adat: a célzás feloldásából keletkezik, kötegelt
        // beszúrással, százezer soronként. Nem admin-művelet — az admin-műveletet (a kampány
        // indítását, a célzás szerkesztését) a `Campaign` auditálja. Soronkénti audit-sor
        // mellett egy százezres kampány százezer törölhetetlen sort írna, a levél
        // címzettjeivel együtt: a legérzékenyebb címlista a legkevésbé törölhető táblában.
        CampaignRecipient::class,
        // A CONSENT-BIZONYÍTÉK maga a napló: append-only, adatbázis-triggerrel védve. Egy
        // audit-sor róla azt jelentené, hogy egy törölhetetlen tábla írását egy másik
        // törölhetetlen táblába is beírjuk — és a bizonyíték nem „admin-művelet", hanem az
        // érintett saját cselekedetének a rögzítése.
        ConsentEvent::class,
        // A KLIKK gépi adat: a címzett kattintására keletkezik, a nyilvános
        // tracking-végponton, felhasználói admin-művelet nélkül. Egy kampány tíz- és
        // százezres sorszámban ír ide — soronkénti audit a törölhetetlen audit-táblát
        // tömné meg forgalommal. Az audit a DÖNTÉSEKET őrzi (ki indított kampányt, ki
        // szerkesztette a célzást), nem azt, hogy egy címzett mire kattintott. A sor
        // amúgy sem tart nyers címet: a címzett PSZEUDONIM hash-ként szerepel.
        LinkClick::class,
        // A MARKETING-TÉNY gépi adat: esemény-figyelőből íródik, címzettenként, egy kampány
        // tíz- és százezres sorszámban. Nem admin-művelet — az admin-műveletet (a kampány
        // indítását, a journey aktiválását) a `Campaign` és a `Journey` auditálja.
        // Soronkénti audit a törölhetetlen audit-táblát tömné meg forgalommal, és a
        // címzett amúgy is pszeudonim hash-ként szerepel.
        CampaignEngagement::class,
        // C1 · A FOGYÁS-SOR gépi adat: minden küldésnél, minden beérkező levélnél és minden
        // címellenőrzésnél nő, felhasználói művelet nélkül. Nem admin-művelet — az
        // admin-műveletet (a periódus LEZÁRÁSÁT, a dunning-állapot váltását) a `Invoice`
        // auditálja, ami `Auditable`. Soronkénti audit itt a törölhetetlen audit-táblát
        // tömné meg forgalommal, és a fogyás-sor amúgy sem tart személyes adatot.
        UsageRecord::class,
        // v1.1 · 7. modul (D175): a DOMAIN-STATISZTIKA számláló-sora gépi adat — az
        // esemény-folyam írja `+1` upserttel, felhasználói művelet nélkül, és PII-t nem tart.
        DomainDailyStat::class,
        // deploy-ovh · A POSTAL-HIVATKOZÁS és a WEBHOOK-NYUGTA gépi adat: küldésenként,
        // címzettenként, illetve kézbesítési eseményenként keletkezik, felhasználói művelet
        // nélkül, és a `logs:purge` lejáratkor törli. Soronkénti audit a törölhetetlen
        // audit-táblát tömné meg forgalommal (a címzett címével együtt).
        PostalMessageRef::class,
        PostalWebhookReceipt::class,
        /*
         | C2 · A `ImpersonationSession` és a `PlanOverride` auditálását a SZOLGÁLTATÁS
         | írja EXPLICITEN — és jobban, mint a trait tudná.
         |
         | A modell `created`/`updated` hookja nem tudná megmondani, hogy egy `ended_at`
         | írás lezárás volt-e vagy lejárat-korrekció, és a TENANT audit-naplójába sem
         | tudná a helyes `workspace_id`-t tenni (a platform-adminnak nincs kontextusa).
         | Az `Impersonation::start/end` és a `PlanOverrides::grant/revoke` négy külön,
         | beszédes audit-műveletet ír — a trait kettő általánost írna.
         */
        ImpersonationSession::class,
        PlanOverride::class,
        /*
         | M4-6 · A `WorkspaceInvitation` auditját is a HÍVÓ írja, három beszédes
         | művelettel (`member.invited`, `member.invitation.redeemed`, `member.invitation.revoked`).
         |
         | A trait itt rosszabb lenne, nem csak szegényesebb: a BEVÁLTÁS a bejelentkezés
         | pillanatában fut, amikor még NINCS workspace-kontextus — a trait a tenant
         | naplója helyett a „nincs workspace" gyűjtőbe írná a tagság keletkezését, pont
         | oda, ahol a tenant sosem keresi. Az explicit hívás a meghívó SAJÁT
         | `workspace_id`-jét és a belépő felhasználót adja át.
         */
        WorkspaceInvitation::class,
    ];

    $offenders = [];

    foreach (File::allFiles(app_path('Modules')) as $file) {
        if ($file->getExtension() !== 'php' || ! str_contains($file->getPathname(), '/Models/')) {
            continue;
        }

        $module = basename(dirname($file->getPath()));
        $class = "App\\Modules\\{$module}\\Models\\".$file->getFilenameWithoutExtension();

        if (! class_exists($class) || in_array($class, $exceptions, true)) {
            continue;
        }

        if (! in_array(Auditable::class, class_uses_recursive($class), true)) {
            $offenders[] = $class;
        }
    }

    expect($offenders)->toBeEmpty('audit nélküli admin-modell: '.implode(', ', $offenders));
});

it('a workspace_id egyetlen modellen sem tömegesen kitölthető', function () {
    $offenders = [];

    foreach (File::allFiles(app_path('Modules')) as $file) {
        if ($file->getExtension() !== 'php' || ! str_contains($file->getPathname(), '/Models/')) {
            continue;
        }

        $module = basename(dirname($file->getPath()));
        $class = "App\\Modules\\{$module}\\Models\\".$file->getFilenameWithoutExtension();

        if (! class_exists($class) || in_array($class, TENANT_SCOPE_EXCEPTIONS, true)) {
            continue;
        }

        if (in_array('workspace_id', (new $class)->getFillable(), true)) {
            $offenders[] = $class;
        }
    }

    expect($offenders)->toBeEmpty('a workspace_id tömegesen kitölthető: '.implode(', ', $offenders));
});
