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

// A JOURNEY-FUTÁSOK LÉPTETÉSE (B3 · 68. kritérium). A motor nem „fut", hanem LÉPTET:
// minden tick megkérdezi az esedékes futásokat. Percenként, mert egy `wait 5 perc` lépés
// pontossága ennél nem lehet rosszabb — és `withoutOverlapping`, mert két párhuzamos tick
// ugyanazt a futást próbálná léptetni (az idempotencia-zár megfogja, de a munka kárba menne).
Schedule::command('journeys:tick')->everyMinute()->withoutOverlapping();

// AZ ESEDÉKES KAMPÁNYOK (65. kritérium). Percenként: a felhasználó „kedd 9:00"-ra ütemez,
// és egy tizenöt perces ciklus azt jelentené, hogy a levél 9:14-kor megy ki. A
// `withoutOverlapping` azért kell, mert egy nagy kampány materializálása másodpercekig tart,
// és a következő futás különben újra elindítaná ugyanazt.
Schedule::command('campaigns:dispatch-scheduled')->everyMinute()->withoutOverlapping();

// A/B GYŐZTES KIHIRDETÉSE. A 64. kritérium „automata kihirdetés"-e enélkül nem létezett: a
// `VariantWinner`-t CSAK a teszt hívta, a győztesnek semmilyen hatása nem volt a küldésre.
Schedule::command('campaigns:announce-winners')->everyFifteenMinutes()->withoutOverlapping();

// A LEZÁRT kampányok címzett-listájának redakciója (golden rule 10). A `campaign_recipients`
// a B2-vel jött, és semmilyen lejárat nem érte: mérve az `erase()` után is NYERS cím maradt
// benne, miközben a `contacts.email` már anonimizált volt.
Schedule::command('campaigns:purge-recipients')->dailyAt('03:50');

// A LEZÁRT journey-futások címének és kontextusának redakciója (golden rule 10). A
// `journey_runs` a B3-mal jött, és semmilyen lejárat nem érte: mérve az `erase()` után is
// nyers cím ÉS nyers trigger-payload (telefonszám, cím) maradt benne.
Schedule::command('journeys:purge-runs')->dailyAt('03:55');

// A KLIKK-TÉRKÉP címzett-dimenziójának összevonása (golden rule 10: „a nyers esemény
// törlődik, az aggregátum marad"). A per-link szám MEGMARAD — ha törölnénk, a fél évvel
// korábbi kampány klikk-térképe kiürülne.
Schedule::command('analytics:purge-click-map')->dailyAt('04:05');

// SZEGMENSBE LÉPÉS mint trigger (69. kritérium). A szegmens egy LEKÉRDEZÉS, nincs „belépés"
// pillanata — ezért ütemezett összehasonlítás adja. A `FireJourneyEvent::segmentEntered()`
// megvolt, a docblock erre a parancsra hivatkozott, de a parancs NEM LÉTEZETT: a
// `segment_entered` triggerű journey élesben soha nem tüzelt.
Schedule::command('journeys:detect-segment-entries')->everyFiveMinutes()->withoutOverlapping();

// A lejárt nyers MIME-levelek törlése (golden rule 10): a levéltest a legsűrűbb PII-tárolónk.
Schedule::command('messages:purge-raw-mime')->dailyAt('03:20');

/*
 | C1 · SZÁMLÁZÁS.
 |
 | A ZÁRÁS a hónap 1-jén, hajnalban: az előző periódust zárja, tehát a hónap utolsó
 | küldései már benne vannak. A `withoutOverlapping` kötelező — két párhuzamos zárás
 | ugyanarra a periódusra az egyedi indexen elhasalna (ami helyes), de a hibás sorok
 | naplója fölöslegesen zajos lenne.
 |
 | A PILLANATKÉP naponta: a csúcs- és állapot-dimenziók (`contacts_peak`,
 | `dedicated_ips`, `log_retention_tier`) állapotok, nem események — napi felbontásuk a
 | tenant javára téved, és a gyakoribb mérés nem tenné pontosabbá a SZÁMLÁT.
 |
 | A DUNNING naponta: a türelmi idők napokban vannak (7 és 30 nap a határidő után), tehát
 | a gyakoribb léptetés semmit nem adna.
 */
Schedule::command('billing:close')->monthlyOn(1, '04:30')->withoutOverlapping();
Schedule::command('billing:snapshot')->dailyAt('04:15');
Schedule::command('billing:dunning')->dailyAt('04:45');
