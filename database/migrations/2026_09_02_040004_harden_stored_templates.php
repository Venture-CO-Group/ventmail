<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 | A sablon-verziók védelme DB-szinten.
 |
 | 1. A tartalom-immutabilitás eddig csak modell-eventen ült, a kód maga viszont
 |    mass-update-tel operál (`versions()->update(['is_active' => false])`) — ott a hook nem
 |    fut le. Ami egyszer kimehetett egy levélben, azt nem szabad átírni: különben nem
 |    lehet megmondani, mit kapott a címzett.
 | 2. Egyszerre EGY aktív verzió lehet, különben az `activeVersion()` nem determinisztikus,
 |    és két párhuzamos aktiválás után nem tudni, melyik sablonnal ment ki a levél.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("create or replace function stored_template_versions_immutable() returns trigger as \$\$
            begin
                -- Kizárólag az aktiválás/deaktiválás mehet át; a TARTALOM nem.
                if to_jsonb(new) - 'is_active' - 'updated_at' = to_jsonb(old) - 'is_active' - 'updated_at' then
                    return new;
                end if;

                raise exception 'A sablon-verzio tartalma nem irhato at — modositas eseten uj verzio keletkezik.';
            end;
        \$\$ language plpgsql");

        DB::statement('drop trigger if exists stored_template_versions_immutable_trigger on stored_template_versions');
        DB::statement('create trigger stored_template_versions_immutable_trigger
            before update on stored_template_versions
            for each row execute function stored_template_versions_immutable()');

        // Egyszerre egyetlen aktív verzió sablononként.
        DB::statement('create unique index stored_template_versions_single_active
            on stored_template_versions (stored_template_id) where is_active');
    }

    public function down(): void
    {
        DB::statement('drop index if exists stored_template_versions_single_active');
        DB::statement('drop trigger if exists stored_template_versions_immutable_trigger on stored_template_versions');
        DB::statement('drop function if exists stored_template_versions_immutable()');
    }
};
