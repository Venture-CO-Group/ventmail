<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | C2 · PLATFORM-ADMIN — séma (81–83., 87. kritérium).
 |
 | ## A platform-admin NEM workspace-szerep
 |
 | A hat tenant-szerep (`owner`…`analyst`) mind egy WORKSPACE-en belül értelmes, és a
 | jogosultságukat a `workspace_members` adja (golden rule 1). A platform-admin ezzel
 | szemben TENANTEKEN ÁTNYÚLVA dolgozik: nincs workspace-e, és épp az a munkája, hogy
 | olyan dolgokat lát, amiket egy tenant nem.
 |
 | Ezért NEM az `AbilityMap`-be kerül (az workspace-scope-olt), hanem a felhasználó
 | sorára — a ventSSO claimjéből, minden bejelentkezésnél újraszámolva. Ez tudatos: ha a
 | jogosultság csak a mi adatbázisunkban élne, egy ventSSO-oldali visszavonás nálunk nem
 | érvényesülne, és a leszerelt kollégának másnap is lenne platform-hozzáférése.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            /*
             | A PLATFORM-ADMIN JELÖLŐ — a ventSSO claimjéből, minden bejelentkezésnél.
             |
             | Miért oszlop és nem session: az ütemezett parancsok és a jobok is
             | eldönthetik, hogy egy művelet platform-adminé volt-e (az audit ezt kéri), és
             | egy session-alapú jelölő a háttérfeldolgozásban nem elérhető. Az ÉRVÉNYESSÉG
             | viszont a bejelentkezéskor újraszámolt claimen múlik, nem ezen az oszlopon:
             | ez CACHE, nem forrás.
             */
            $table->boolean('platform_admin')->default(false)->after('locale');
        });

        /*
         | AZ IMPERSONATION-MUNKAMENET (82. kritérium).
         |
         | ## Miért TÁBLA, és nem csak session-kulcs
         |
         | Mert a művelet KEZDETE és VÉGE is auditálandó, és mert a tenantnak is látnia kell,
         | hogy megtörtént — az nem a mi belső ügyünk, hanem az övé. Egy session-kulcs a
         | böngésző bezárásával nyomtalanul eltűnne, és a „meddig tartott" kérdésre nem
         | lenne válasz.
         |
         | ## AZ IDŐKORLÁT az adatbázisban van
         |
         | A `expires_at` nélkül egy elfelejtett impersonation hetekig élne. A támogatás
         | percekig néz egy fiókot, nem napokig.
         */
        Schema::create('impersonation_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            // AZ ADMIN, aki átveszi a nézetet.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // A WORKSPACE, amit megnéz. NEM `BelongsToWorkspace`: ez a sor a PLATFORM
            // adata, nem a tenanté — a tenant a saját audit-naplójában látja a tényt.
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            // MIÉRT — kötelező, szabad szöveg. Egy támogatási ügy száma vagy egy leírás.
            // A „csak megnéztem" nem elfogadható indok egy idegen fiók megnyitásához.
            $table->string('reason');

            $table->timestampTz('started_at');
            $table->timestampTz('expires_at');
            $table->timestampTz('ended_at')->nullable();
            $table->timestamps();

            // A LEJÁRT és a LEZÁRT munkamenetek keresése: az aktív munkamenet
            // megállapítása minden kérésnél ezen az indexen megy.
            $table->index(['user_id', 'ended_at', 'expires_at']);
            $table->index(['workspace_id', 'started_at']);
        });

        /*
         | A PLAN-OVERRIDE (83. kritérium).
         |
         | ## Miért LEJÁRATTAL
         |
         | Mert az override kivétel, nem állapot. A támogatás megemeli egy tenant kvótáját
         | egy kampány idejére, vagy bekapcsol egy funkciót egy próbaidőre — és utána
         | MAGÁTÓL vissza kell állnia. A visszavonás nélküli override az, amit senki nem
         | vesz vissza: fél év múlva már senki nem tudja, miért van egy tenantnak ingyen
         | dedikált IP-je.
         |
         | ## A ventPay-t NEM írjuk át
         |
         | Az override a ventMail oldalán ÜL RÁ a ventPay válaszára — a ventPay marad a
         | csomag gazdája (egy képesség, egy gazda). Ha a ventMail írná át a csomagot, két
         | rendszer mondaná meg, mit fizet a tenant.
         */
        Schema::create('plan_overrides', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            // Az override-ot LÉTREHOZÓ platform-admin: az audit mellett itt is, mert a
            // riportban ez a leggyakoribb kérdés („ki adta ezt?").
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            /*
             | A FELÜLÍRT funkciók listája és a kvóta.
             |
             | A `features` jsonb: ami benne van, azt a `EntitlementChecker` ENGEDI, a
             | ventPay válaszától függetlenül. Ami NINCS benne, arról az override nem
             | mond semmit — a ventPay dönt. Tehát az override HOZZÁAD, nem helyettesít:
             | egy üres lista nem zár be mindent.
             */
            $table->jsonb('features')->default('[]');

            // A KVÓTA felülírása. `null` = nem nyúlunk hozzá.
            $table->unsignedBigInteger('send_quota')->nullable();

            $table->string('reason');
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestamps();

            // AZ AKTÍV override keresése: minden entitlement-kérdésnél ez fut.
            $table->index(['workspace_id', 'revoked_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_overrides');
        Schema::dropIfExists('impersonation_sessions');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('platform_admin');
        });
    }
};
