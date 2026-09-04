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

// Warmup-léptetés és reputáció-alapú visszafogás (A2, 17. kritérium). Enélkül a napi limit
// az maradna, amit valaki kézzel beírt, és a romló reputáció nem járna következménnyel.
Schedule::command('ip:reputation')->hourly();

// Az esedékes webhook-újrapróbálások (A3): a halott végpont felé nem másodpercenként
// próbálkozunk, de a pillanatnyi hiba után igen.
Schedule::command('webhooks:retry')->everyMinute()->withoutOverlapping();

// A lejárt napló-melléktáblák (SMTP-párbeszéd, kísérletek, webhook-sorok) takarítása.
Schedule::command('logs:purge')->dailyAt('03:40');

// A bejövő levél IDEGEN tartalom, rövid retencióval (alap 3 nap): GYAKRABBAN takarítunk,
// mint a saját naplónkat, és a fájltárat is ürítjük vele.
Schedule::command('inbound:purge')->hourly();

// A lejárt nyers MIME-levelek törlése (golden rule 10): a levéltest a legsűrűbb PII-tárolónk.
Schedule::command('messages:purge-raw-mime')->dailyAt('03:20');
