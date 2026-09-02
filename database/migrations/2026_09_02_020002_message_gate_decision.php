<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | A gate-döntés IDEJE az üzenetre kerül.
 |
 | Enélkül egy holnapra ütemezett kampánynál a közben érkezett leiratkozás, hard bounce,
 | kvóta-kimerülés vagy domain-deverifikáció NEM számítana: a dispatcher csak az `accepted`
 | státuszt nézné. A döntésnek szavatossági ideje van.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->timestamp('gate_decided_at')->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('gate_decided_at');
        });
    }
};
