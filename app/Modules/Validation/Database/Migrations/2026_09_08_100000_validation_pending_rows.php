<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | A BULK futás sorai ELŐRE beszúrva, `pending` állapotban.
 |
 | A korábbi változat a címeket a JOB PAYLOADJÁBAN vitte, és két dolgot rontott el egyszerre:
 |
 | 1. DUPLIKÁLÁS. A `catch` `failed`-re állított, majd dobott; a `$tries = 3` miatt a job
 |    újrafutott, az őr viszont csak a `completed` állapotot nézte — tehát a TELJES batch
 |    újra feldolgozódott, az előző kísérlet sorait meghagyva. Mérve: `processed=6` egy
 |    `total=3` futáson, minden cím kétszer az exportban, és a ventPay `validations_counter`
 |    duplán számol → HIBÁS SZÁMLA.
 |
 | 2. MEMÓRIA. Százezer cím a payloadban ~6 MB serializálva, chunkonként újra és újra a
 |    sorba téve — több száz megabájt queue-forgalom EGYETLEN listára.
 |
 | A `pending` sorok mindkettőt megoldják: a job csak a futás azonosítóját viszi, a haladás az
 | ADATBÁZISBAN van, és az egyediségi index a duplikálást SÉMA-SZINTEN zárja ki.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('validation_results', function (Blueprint $table): void {
            $table->string('result')->default('pending')->change();
        });

        // Az EGYEDISÉG a sémában: az idempotencia nem a hívó figyelmén múlik.
        Schema::table('validation_results', function (Blueprint $table): void {
            $table->unique(['validation_job_id', 'address'], 'validation_results_job_address_unique');
        });
    }

    public function down(): void
    {
        Schema::table('validation_results', function (Blueprint $table): void {
            $table->dropUnique('validation_results_job_address_unique');
        });
    }
};
