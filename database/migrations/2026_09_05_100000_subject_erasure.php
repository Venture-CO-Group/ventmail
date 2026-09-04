<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | ÉRINTETTI TÖRLÉS (GDPR Art. 17) — a FŐTÁBLA és a TARTÓS TILTÁS.
 |
 | Két hiány zárul itt:
 |
 | 1. A `messages` tábla a napló FŐtáblája: ott van a címzett-lista, a tárgy és a kirenderelt
 |    törzs. A melléktáblák redakciója önmagában nem teljesítés — a napló-képernyő a törlés
 |    után is kiírta volna az érintettet.
 |
 | 2. A törlés VISSZAFORDÍTHATÓ volt. A már kiküldött levélben ott a nyomkövetési pixel, ami
 |    titkosítva hordozza a címzettet; egy betöltés új eseményt írt volna a törölt címmel —
 |    és a webhookon harmadik félhez is kiküldte volna. Az Apple MPP és a Gmail képproxyja
 |    ezt felhasználói szándék nélkül, magától megteszi.
 |
 |    Ezért kell TARTÓS tiltás. A tábla a cím HASH-ét tárolja, nem a címet: a törlési kérés
 |    nyilvántartása nem lehet maga is egy címlista.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->timestampTz('redacted_at')->nullable();
        });

        Schema::create('erased_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            // sha256 a NORMALIZÁLT címről. A nyers cím sosem kerül ide: különben a törlési
            // kérések nyilvántartása lenne a legérzékenyebb címlistánk.
            $table->char('recipient_hash', 64);
            $table->timestampTz('erased_at');
            $table->timestamps();

            $table->unique(['workspace_id', 'recipient_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erased_recipients');

        Schema::table('messages', function (Blueprint $table): void {
            $table->dropColumn('redacted_at');
        });
    }
};
