<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | FO3 — a ventCookie e-mail-consent főkönyv NYUGTÁJA a helyi consent-rekordon.
 |
 | `ledger_receipt_id`: a főkönyv legutóbbi nyugtájának azonosítója (a főkönyvbe tett
 | legfrissebb bizonyíték). `ledger_receipt_at`: annak a művelet-időpontja, amelyikhez a
 | nyugta tartozik — a késve érkező, RÉGEBBI nyugta így nem írja felül az újabbat.
 |
 | Expand-only: két új, nullable oszlop. A helyi consent marad a hivatalos; ezek csak
 | hivatkozások a külső, hash-láncolt bizonyítékra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consents', function (Blueprint $table) {
            $table->string('ledger_receipt_id', 128)->nullable();
            $table->timestamp('ledger_receipt_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('consents', function (Blueprint $table) {
            $table->dropColumn(['ledger_receipt_id', 'ledger_receipt_at']);
        });
    }
};
