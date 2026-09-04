<?php

declare(strict_types=1);

use App\Modules\Audit\Concerns\Auditable;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Events\Models\MessageEvent;
use App\Modules\Inbound\Models\InboundAttachment;
use App\Modules\Inbound\Models\InboundMessage;
use App\Modules\Logs\Models\SmtpTransaction;
use App\Modules\Sending\Models\DeliveryAttempt;
use App\Modules\Sending\Models\Message;
use App\Modules\Sending\Models\RawMimeMessage;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use App\Modules\Tenancy\Concerns\BelongsToWorkspaceOrPlatform;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Models\WorkspaceMember;
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
