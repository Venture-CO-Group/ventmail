<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | A KIVÁLASZTOTT IP az üzenetre kerül, és a kézbesítési kísérletek nyomon követhetők.
 |
 | Az A3 üzenet-trace („miért nem érkezett meg?") kötelező eleme: melyik IP-ről ment, és mit
 | válaszolt a fogadó. A `next_attempt_at` a visszalépéshez kell — enélkül egy 4xx-re vagy
 | azonnal újrapróbálnánk (és a fogadó greylistje sosem oldódna fel), vagy soha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('ip_address_id')->nullable()->after('ip_pool_id')->constrained('ip_addresses')->nullOnDelete();
            $table->timestampTz('next_attempt_at')->nullable()->after('attempts')->index();
        });

        Schema::create('delivery_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->uuid('message_uuid')->index();
            $table->string('recipient');
            $table->unsignedSmallInteger('attempt');
            $table->unsignedSmallInteger('code')->nullable();       // SMTP válaszkód
            $table->string('enhanced_code', 16)->nullable();        // X.Y.Z
            $table->text('response')->nullable();                   // a fogadó szövege
            $table->string('classification')->nullable();           // hard|soft|blocked|spam|mailbox_full|policy
            $table->foreignId('ip_address_id')->nullable()->constrained('ip_addresses')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'message_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_attempts');

        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ip_address_id');
            $table->dropColumn('next_attempt_at');
        });
    }
};
