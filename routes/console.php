<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 | A domain-hitelesítés ÚJRAELLENŐRZÉSE (golden rule 8).
 |
 | Enélkül a `verified → failed` visszaesés élesben SOHA nem következne be: egy törölt
 | DKIM-rekord után a domain örökre `verified` maradna, és a PreSendGate 1. kapuja
 | továbbengedné a hitelesítetlen küldést.
 */
Schedule::command('domains:verify --pending')->everyFifteenMinutes();
Schedule::command('domains:verify --verified')->daily();

// A lejárt előző DKIM privát kulcs törlése (a kompromittálódás ablakának zárása).
Schedule::command('domains:purge-dkim')->daily();

// Az esedékes, ütemezett üzenetek kiküldése — a kapuk ÚJRAÉRTÉKELÉSÉVEL (a közben érkezett
// leiratkozásnak számítania kell).
Schedule::command('messages:dispatch-scheduled')->everyMinute()->withoutOverlapping();
