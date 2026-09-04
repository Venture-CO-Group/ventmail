<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | A `forward(url)` ALÁÍRÓ TITKA — route-onként.
 |
 | Eddig egy `APP_KEY`-ből származtatott érték írta alá a továbbított levelet, amit SEHOL nem
 | adtunk ki. Egy aláírás, amit a fogadó nem tud ellenőrizni, nem aláírás — és rotálni sem
 | lehetett volna az `APP_KEY` cseréje nélkül, ami az összes többi titkosított oszlopot is
 | elvinné.
 |
 | Ugyanaz a minta, mint az A3 webhook-végpontjainál: titkosítva tárolt, a létrehozás
 | válaszában EGYSZER megmutatott, külön rotálható titok.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbound_routes', function (Blueprint $table): void {
            $table->text('signing_secret')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inbound_routes', function (Blueprint $table): void {
            $table->dropColumn('signing_secret');
        });
    }
};
