<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/*
 | Őrszem a global scope megkerülési útjaira. A Tenancy modul a scope gazdája; rajta kívül
 | senki nem kerülheti meg (golden rule 6: „global scope megkerülése" = instant blokk).
 */

it('a Tenancy modulon kívül senki nem kerüli meg a global scope-ot', function () {
    $offenders = [];

    foreach (File::allFiles(app_path('Modules')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        // A MIGRÁCIÓK nem futásidejű lekérdezések: ott a `DB::statement` a séma nyelve
        // (részleges index, trigger, Timescale-hívás), nem a global scope megkerülése.
        if (str_contains($file->getPathname(), '/Database/Migrations/')) {
            continue;
        }

        $relative = $file->getRelativePathname();

        // Dokumentált kivétel: az API-kulcs feloldása a kontextus FORRÁSA — lookup-időben
        // még nincs workspace. A kikapcsolás ott egyetlen, szűk, hash-egyezésre korlátozott
        // lekérdezésre szól, és teszt fedi (ApiKeyLookupTest).
        // Ugyanez a precedens az SMTP-hitelesítőre: AUTH-időben még nincs workspace, a
        // kontextus MAGÁBÓL a hitelesítőből származik. Mindkettő egyetlen, szűk, pontos
        // egyezésre korlátozott lekérdezés, izolációs teszttel fedve.
        // Az IP-reputáció SZÁNDÉKOSAN tenant-független: egy megosztott IP-ről sok tenant
        // küld, és a bounce-arány CSAK az összes forgalomra értelmes. Tenantonként számolva
        // egyetlen kis ügyfél két bounce-a 100%-os aránynak látszana. A parancs kizárólag
        // DARABSZÁMOT olvas (címzettet, tartalmat nem), és nem ír tenant-adatot.
        // A folytonos aggregátum (`message_event_daily`) NÉZET: nincs rá Eloquent-modell,
        // tehát global scope sem lehet rajta. Ezért a workspace-szűrő egyetlen helyre,
        // ebbe a szolgáltatásba van bezárva — a kontextus hiánya ott KIVÉTEL, nem
        // „minden tenant" —, és izolációs teszt fedi (AggregateRetentionTest).
        // A BEJÖVŐ címzett feloldása: fogadáskor még NINCS workspace-kontextus — épp ez a
        // lekérdezés állapítja meg, melyik tenanté a levél. Ugyanaz a precedens, mint az
        // API-kulcsnál és az SMTP-hitelesítőnél: egyetlen, szűk, pontos egyezésre
        // korlátozott lekérdezés, izolációs teszttel fedve (InboundIsolationTest).
        // A DMARC-jelentés BETÖLTÉSE és az MTA-STS policy KISZOLGÁLÁSA ugyanaz a minta: a
        // kérést egy IDEGEN fél indítja (a fogadó levelezőrendszere, illetve egy távoli MTA),
        // bejelentkezés és kontextus nélkül — épp ez a lekérdezés állapítja meg, melyik
        // tenanté a domain. Mindkettő egyetlen, `name` + `status = verified` egyezésre
        // korlátozott lekérdezés, `limit(2)`-vel az egyértelműség őrzésére, teszttel fedve.
        // B1 · A PUBLIKUS ŰRLAP ugyanaz a minta: a beküldés NÉVTELEN, tehát tenant-kontextus
        // nélkül érkezik — épp ez a lekérdezés állapítja meg, melyik tenanté az űrlap.
        // Egyetlen, `slug` + `active` egyezésre korlátozott lekérdezés, `limit(2)`-vel az
        // egyértelműség őrzésére.
        //
        // A `ContactGdpr` NEM scope-megkerülés: a query buildert a KONTEXTUSON BELÜL
        // használja, és pontosan azért, mert a modell-MUTÁTORT kell megkerülni. Az
        // anonimizálás az `email` oszlopot üríti, miközben az `email_hash` MEGMARAD — a
        // mutátor ezt újraszámolná az üres címből, és a suppression-tiltás
        // felismerhetetlenné válna, vagyis a törlési kérés eredménye az lenne, hogy az
        // érintett újra kap levelet. A lekérdezések elsődleges kulcsra mennek
        // (`where('id', …)`), tehát tenant-határt nem tudnak átlépni.
        // C1 · A KONSZOLIDÁLT SZÁMLA a parent és a childjei kivonatait EGYÜTT olvassa. A
        // global scope `workspace_id = parent`-re szűr, tehát a child sorokat elrejtené —
        // pedig épp azokat kell összesíteni (77. kritérium). A szűrő kézi és PONTOS: vagy a
        // parent saját sora, vagy olyan, aminek a `parent_workspace_id`-je ÉPP EZ a parent;
        // idegen tenant sora egyik ágon sem férhet bele. A `runFor()` a Tenancy SAJÁT
        // kontextus-váltója (a `closeConsolidated()` ezt használja, nem scope-kikapcsolást).
        $exceptions = [
            'Billing/Services/MonthlyClose.php',
            'Deliverability/Services/DmarcReportIngestor.php',
            'Deliverability/Http/Controllers/MtaStsController.php',
            'Audience/Http/Controllers/PublicFormController.php',
            'Audience/Services/ContactGdpr.php',
            'Inbound/Services/VerifiedInboundResolver.php',
            'Logs/Services/TagStatistics.php',
            'Identity/Models/ApiKey.php',
            'Smtp/Models/SmtpCredential.php',
            'IpPools/Console/UpdateIpReputationCommand.php',
        ];

        if (str_starts_with($relative, 'Tenancy/') || in_array($relative, $exceptions, true)) {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        $bypasses = '/withoutGlobalScope|withoutGlobalScopes|->runFor\(|DB::table\(|DB::(select|statement|update|delete)\(/';

        if (preg_match($bypasses, $contents)) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBeEmpty('global scope megkerülése a Tenancy modulon kívül: '.implode(', ', $offenders));
});
