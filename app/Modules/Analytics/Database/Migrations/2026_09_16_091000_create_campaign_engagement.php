<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | B4 · A MARKETING-RIPORT TÉNYTÁBLÁJA (72., 73. kritérium).
 |
 | ## Miért KELL, és mit mértünk nélküle
 |
 | A riport először a NYERS `message_events`-ből számolt. A táblán viszont ott a 90 napos
 | retenció (`add_retention_policy('message_events', interval '90 days')`), és a review
 | ezt megmérte egy 100 napos kampányon, `drop_chunks` után:
 |
 |     Studio-riport:   delivered 0,  opened 0,  open_rate 0%
 |     Platform-napló:  delivered 50, opened 20, open_rate 40%
 |
 | Ugyanaz a kampány, ugyanaz a tag, KÉT felület, KÉT szám — pontosan az, amit a 73.
 | kritérium tilt, és amit a `TagStatistics` docblockja előre kimondott: „aki ezt a
 | szolgáltatást megkerüli, az egy önmagát törlő statisztikát épít".
 |
 | A napi IDŐSOR ezért a MEGLÉVŐ Timescale folytonos aggregátumból jön
 | (`message_event_daily`) — abból, amit a napló is olvas, tehát a két felület
 | szerkezetileg nem tud elcsúszni. Az EGYEDI számokat viszont egy aggregátum nem tudja
 | (a continuous aggregate nem támogat `DISTINCT`-et), és épp az egyedi nyitó/kattintó a
 | marketing-riport lényege. Ezért ez a ténytábla.
 |
 | ## A GRAIN: (hatókör, variáns, CÍMZETT) — és miért a címzett is
 |
 | Mert az egyediség CSAK címzett-dimenzióval számolható exaktul. Ez a tábla a
 | `campaign_recipients`-szel egy nagyságrend (soronként egy címzett), és ugyanúgy
 | pszeudonim: a `recipient_hash` kulcsolt HMAC, amivel az egyediség számolható, az
 | azonosítás nem.
 |
 | Ez EGYBEN megoldja azt is, amit a review a nyers úton mért: a `count(distinct recipient)`
 | a GDPR-redakció után VISSZAMENŐLEG lerontotta a riportot (a redakció `NULL`-ra állítja a
 | `recipient`-et, és a Postgres a `NULL`-okat nem egynek, hanem NULLÁNAK számolja — mérve
 | 5 egyedi nyitóból 2 lett három törlési kérés után). A hash redakció-tűrő: a törlés
 | ÖSSZEVONJA a sorokat, a számok megmaradnak.
 |
 | ## A `scope` STRING, és miért nem két nullable FK
 |
 | Mert a Postgresben a `NULL`-ok az egyedi indexben KÜLÖNBÖZŐEK: egy
 | `(campaign_uuid, journey_id, variant, recipient_hash)` unique nem védene semmit, ha a
 | `journey_id` `NULL`. A `scope` egyetlen, mindig kitöltött kulcs (`campaign:<uuid>` vagy
 | `journey:<id>`), tehát az `upsert` idempotenciája valóban áll.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_engagement', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            // `campaign:<uuid>` | `journey:<id>` — mindig kitöltött (lásd a docblockot).
            $table->string('scope');

            // Az A/B variáns címkéje. ÜRES sztring, nem `NULL`: az egyedi index kulcsa.
            $table->string('variant_label')->default('');

            // PSZEUDONIM címzett: az egyediség számolható, az azonosítás nem.
            $table->string('recipient_hash', 64);

            /*
             | ÁLLAPOT-SZÁMLÁLÓK címzettenként.
             |
             | Miért számláló és nem boolean: a `delivered` egy címzettre elvileg egyszer
             | jön, de a valóságban a driver-út kétszer is írhat `accepted`-et (a
             | `SendMessage` a soron, a `MessageDispatcher` a driver válaszán). A review ezt
             | megmérte: a `bounce_rate` nevezője kétszer számolt, és a ráta 50%-ot mutatott
             | 100% helyett. A riport ezért a CÍMZETTEKET számolja (`count(*) where … > 0`),
             | nem az eseményeket — de a nyers eseményszám is megmarad, mert a „hány
             | megnyitás" külön kérdés a „hányan nyitották meg"-től.
             */
            /*
             | A KAPU ELUTASÍTÁSA — és miért KELL a riportba.
             |
             | A PreSendGate elutasításai (`suppression`, hiányzó consent, kvóta,
             | címellenőrzési kockázat) `rejected` eseményt írnak a kampány tagjével. A
             | riport ezt korábban nem ismerte: a `recipients: 100000` mellett
             | `accepted: 60000` állt, MAGYARÁZAT NÉLKÜL — a tenant nem látta, hogy a
             | listája 40%-a ki sem ment. A listahigiénia romlása a kézbesíthetőségi
             | probléma ELSŐ jele, és épp ezt rejtette el a hiányzó szám.
             */
            $table->unsignedInteger('rejected')->default(0);

            $table->unsignedInteger('accepted')->default(0);
            $table->unsignedInteger('delivered')->default(0);
            $table->unsignedInteger('deferred')->default(0);
            $table->unsignedInteger('bounced')->default(0);
            $table->unsignedInteger('complained')->default(0);
            $table->unsignedInteger('unsubscribed')->default(0);
            $table->unsignedInteger('opens')->default(0);
            $table->unsignedInteger('clicks')->default(0);

            // AZ ELSŐ reakció ideje: „mennyi idő alatt nyitotta meg" valódi mérőszám.
            $table->timestampTz('first_opened_at')->nullable();
            $table->timestampTz('first_clicked_at')->nullable();
            $table->timestampTz('last_event_at')->nullable();
            $table->timestamps();

            /*
             | AZ IDEMPOTENCIA: egy esemény kétszeri feldolgozása nem duplikál sort.
             |
             | A `workspace_id` BENNE VAN, és ez nem redundancia. Az `upsert` INSERT, tehát
             | a global scope nem hat rá — az ütközési célnak (`ON CONFLICT`) ezért magának
             | kell tartalmaznia a tenant-oszlopot, különben KÉT tenant ugyanazon
             | (hatókör, variáns, címzett) hármasa EGY sorra ütközne, és az egyik a másik
             | számlálóját növelné. A review ugyanezt a rést mérte meg a `link_clicks`-en.
             */
            $table->unique(['workspace_id', 'scope', 'variant_label', 'recipient_hash'], 'campaign_engagement_unique');

            // A RIPORT lekérdezése: hatókör (+ variáns) szerinti aggregálás.
            $table->index(['workspace_id', 'scope', 'variant_label'], 'campaign_engagement_scope');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_engagement');
    }
};
