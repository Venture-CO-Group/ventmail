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
        // C1 · A DUNNING KÉT okból tenant-független, és mindkettő szándékos.
        //
        // (a) A `advance()` PLATFORM-SZINTŰ állapotgép: az ütemezett parancs minden nyitott
        //     kivonatot léptet, tenant-dimenzió nélkül. Tenantonként futtatva ugyanaz a
        //     `WHERE due_at <= …` feltétel ezerszer futna le ugyanarra a táblára, és a
        //     léptetés nem is tenant-döntés — a HATÁRIDŐ dönt.
        // (b) A `isSuspended()` a SZÁMLÁZOTT workspace állapotát kérdezi, ami egy
        //     sub-accountnál a PARENT — a global scope viszont a child-ra szűrne, tehát a
        //     felfüggesztés egy alszámla létrehozásával megkerülhető lenne. A szűrő szigorú
        //     egyenlőség egy kiszámított id-re.
        //
        // Egyik ág sem olvas tenant-TARTALMAT: kizárólag állapotot és határidőt.
        // C1 · A `Metering::write()` NYERS `INSERT … ON CONFLICT`-ot használ, mert az
        // Eloquent `upsert()` nem tudja kifejezni a FELTÉTELES ütközési ágat
        // (`… do update … where usage_records.closed_at is null`). Ez a `WHERE` az, ami
        // ATOMIVÁ teszi a lezárt periódus kihagyását: két utasítással (SELECT + upsert) a
        // zárás alatt beeső küldés mérve ELVESZETT — a levél kimegy, a fogyás nem kerül
        // számlára —, és a kérés a zárás idejére be is akadt a sorzáron.
        //
        // A `workspace_id` KÖTÖTT PARAMÉTERként megy be, a kontextusból (`assertContext()`
        // fail-closed) — nem sztring-interpolációval, és nem is elhagyhatóan. A lekérdezés
        // egyetlen sorra hat, aminek mindhárom kulcsmezője kötött.
        // C2 · A PLATFORM-ADMIN IP-FELÜGYELETE tenanteken átnyúlva olvas.
        //
        // Az `IpAddress` SZÁNDÉKOSAN nem workspace-kötött (a küldő IP a MI
        // infrastruktúránk), a `IpPool` viszont tenant- VAGY platform-szintű: kontextus
        // nélkül a scope fail-closed módon SEMMIT nem ad. Egy üzemeltetői felület, ami
        // csak az „aktuális tenant" pooljait mutatja, nem üzemeltetői felület — a
        // karanténba tett IP MINDEN tenant forgalmát átirányítja.
        //
        // A kikapcsolás EGYETLEN lekérdezésre szól (a poolok betöltése `keyBy`-jal), és
        // csak OLVASÁSRA: az írás (karantén, feloldás) az `IpAddress`-en megy, amin nincs
        // scope, és minden művelet auditált + `platform.admin`-hoz kötött.
        // C2 · A `AuditLog::whileRetentionPruning()` `DB::statement`-je SÉMA-NYELV, nem
        // lekérdezés: egyetlen session-változót állít be (`ventmail.audit_prune`), amit a
        // tábla triggere követel meg a retenciós törléshez. UGYANAZ a minta, mint a
        // Tenancy `BelongsToWorkspaceOrPlatform::onPlatform()`-jánál — a valódi határ az
        // adatbázisban van, mert a builder-úton a modell-event nem fut le.
        //
        // Scope-ot nem kerül meg: az `AuditLog`-on SZÁNDÉKOSAN nincs global scope (a
        // naplónak kontextus nélkül is íródnia kell), és a törlés a 365 napos DB-oldali
        // alsó időhatár alatt akkor sem megy át, ha a szándék-jelzés megvan.
        $exceptions = [
            'Audit/Models/AuditLog.php',
            'Billing/Services/Dunning.php',
            'SuperAdmin/Services/IpSupervision.php',
            'Billing/Services/Metering.php',
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
