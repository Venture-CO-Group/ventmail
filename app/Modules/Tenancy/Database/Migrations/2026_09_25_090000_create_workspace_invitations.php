<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | MEGHÍVÓ = ELŐRE MEGADOTT TAGSÁG, nem felhasználó-létrehozás.
 |
 | A ventMail nem gazdája az identitásnak (golden rule 1): felhasználót a ventSSO hoz létre,
 | jelszót itt senki nem állít. A tagság viszont a MI táblánk — és eddig CSAK olyan embernek
 | lehetett szerepet adni, aki már bejelentkezett ide legalább egyszer. Vagyis egy új
 | kolléga felvétele lehetetlen volt a felületről: nem létezett a `users` sora.
 |
 | A meghívó ezt a rést zárja be: rögzíti, hogy EGY CÍMHEZ milyen szerepek járnak, ha az a
 | cím egyszer belép a ventSSO-n. A beváltás a bejelentkezéskor történik, és KIZÁRÓLAG
 | VERIFIKÁLT e-mail-címre — enélkül bárki, aki a ventSSO-ban regisztrál egy idegen címmel,
 | belépne a meghívott workspace-be (pre-registration account takeover). Ugyanez az indok,
 | amiért a `SyncSsoUser` a cím változásakor törli a verifikáltságot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_invitations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            // A CÍM normalizált (kisbetűs, trimmelt) alakban tárolódik: a beváltás pontos
            // egyezést néz, és a `Nagy.Bela@Pelda.HU` alak különben sosem találna.
            $table->string('email', 320);
            $table->jsonb('roles');
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'accepted_at']);
            $table->index('email');
        });

        // EGY NYITOTT MEGHÍVÓ workspace-enként és címenként. Részleges index, mert a
        // beváltott meghívók megmaradnak (nyom), és azokból több is lehet ugyanarra a címre:
        // aki kilépett és visszajött, kétszer kapott meghívót.
        DB::statement('create unique index workspace_invitations_open_unique
            on workspace_invitations (workspace_id, email) where accepted_at is null');
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_invitations');
    }
};
