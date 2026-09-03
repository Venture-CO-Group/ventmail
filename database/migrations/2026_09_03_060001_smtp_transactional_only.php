<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 | Marketing SMTP-n: VÉGLEG TILTVA (tulajdonosi döntés, ADR 0001).
 |
 | A `channel_restriction` operátori kiskapu megszűnik. Miért DB-szintű kényszer: az ingest
 | már fix `transactional`-t küld, de egy oszlop, amit „csak indokolt esetben" állít valaki,
 | előbb-utóbb támogatási kérésre átbillen — a check-constraint ezt a lehetőséget is
 | megszünteti. Consent-bizonyítékot SMTP-fejlécből nem lehet előállítani (golden rule 4).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("update smtp_credentials set channel_restriction = 'transactional' where channel_restriction is distinct from 'transactional'");

        DB::statement("alter table smtp_credentials
            add constraint smtp_credentials_transactional_only
            check (channel_restriction = 'transactional')");
    }

    public function down(): void
    {
        DB::statement('alter table smtp_credentials drop constraint if exists smtp_credentials_transactional_only');
    }
};
