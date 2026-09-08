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

// A bejövő fogadás BIZALMI HORGONYA: az MX-rekord bármikor eltűnhet (domain-átvitel,
// DNS-hiba, az ügyfél átállt máshova), és onnantól nem a mi dolgunk levelet fogadni rá.
Schedule::command('inbound:verify-mx')->hourly();

// A feltöltött címlista az ügyfél TELJES címlistája lehet egy fájlban — ennél érzékenyebb
// adat kevés van a rendszerben, és az ellenőrzés után nincs jogalapunk a megőrzésére.
Schedule::command('validation:purge')->dailyAt('03:50');

// BLOCKLIST-FIGYELÉS: ez az a hiba, amit MI veszünk észre utoljára — az ügyfél előbb, mert
// nála hirtelen senki nem kapja meg a levelet. Az óránkénti futás a delisting-kérelemhez
// szükséges időt adja meg.
Schedule::command('deliverability:blocklists')->hourly();

// A kézbesíthetőségi suite lejárt adatai (blocklist-napló, DMARC-jelentés, seed-mérés).
// A `blocklist_checks` óránként `IP × zóna` sorral nő: takarítás nélkül évi milliós tábla.
Schedule::command('deliverability:purge')->dailyAt('04:10');

// AZ ESEDÉKES KAMPÁNYOK (65. kritérium). Percenként: a felhasználó „kedd 9:00"-ra ütemez,
// és egy tizenöt perces ciklus azt jelentené, hogy a levél 9:14-kor megy ki. A
// `withoutOverlapping` azért kell, mert egy nagy kampány materializálása másodpercekig tart,
// és a következő futás különben újra elindítaná ugyanazt.
Schedule::command('campaigns:dispatch-scheduled')->everyMinute()->withoutOverlapping();

// A lejárt nyers MIME-levelek törlése (golden rule 10): a levéltest a legsűrűbb PII-tárolónk.
Schedule::command('messages:purge-raw-mime')->dailyAt('03:20');
