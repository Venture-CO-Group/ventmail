<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | B4 · Marketing analitika — a KLIKK-TÉRKÉP tárolója (74. kritérium).
 |
 | ## Miért KELL külön tábla, és miért nem a `message_events`-ben van
 |
 | Mert a kattintott CÍM SZÁNDÉKOSAN nincs az eseményben. A `TrackingController` ezt maga
 | mondja ki: „a kattintott cím nem kerül az eseménybe: a webhook harmadik félhez viszi ki,
 | és egy link gyakran tokent vagy azonosítót hordoz". Ez helyes döntés, és nem írjuk vissza
 | — a `MessageEvent::created` hookja a payloadot a kimenő webhookba teszi, tehát bármi, ami
 | oda bekerül, elhagyja a rendszert.
 |
 | A klikk-térkép viszont pontosan azt kérdezi, hogy MELYIK LINKRE kattintottak. Ezért a
 | link a MI adatbázisunkban marad, egy külön táblában, ami SOHA nem kerül webhook-payloadba.
 |
 | ## Miért van benne a `recipient_hash`, és miért NEM a nyers cím
 |
 | A „hány EGYEDI ember kattintott erre a linkre" kérdéshez a címzett-dimenzió kell. A nyers
 | cím viszont itt semmit nem ad: a térkép aggregált nézet, nem címzett-lista. A kulcsolt
 | hash (`KeyedHash`) pszeudonim — az egyediség számolható vele, az azonosítás nem —, és a
 | GDPR-törlés a hash-t is redaktálja (`logs:redact-subject`).
 |
 | Az EGYEDI INDEX `(message_uuid, url_hash, recipient_hash)` egyben az IDEMPOTENCIA: egy
 | újratöltött link nem duplikál sort, csak a számlálót növeli.
 |
 | ## A LINKENKÉNTI FELSŐ KORLÁT
 |
 | Ha a tenant merge-taget tesz a `href`-be, a behelyettesített cím CÍMZETTENKÉNT MÁS —
 | tehát `url_hash` is. Egy 100 000 címzettes kampány így 100 000 külön „linket" adna, és a
 | térkép használhatatlan lenne (plusz a tábla hízna). Ezért az üzenetenkénti DISTINCT
 | link-szám korlátos: a korlát fölötti kattintás egyetlen `(egyéb)` sorba gyűlik. A
 | csonkítás TÉNYE látszik a riportban — nem csendes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_clicks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            // Az ÜZENET uuid-ja, nem FK: a `messages` sor a napló-retencióval törlődhet, a
            // klikk-térkép viszont a kampány-riport része, és annak túl kell élnie.
            $table->uuid('message_uuid')->index();

            /*
             | A KAMPÁNY / JOURNEY hozzárendelés a TAG-ekből jön, a küldés pillanatában.
             |
             | Miért denormalizált: a térképet kampányonként kérdezzük, és a tagek jsonb
             | tömbben állnak az eseményen. Egy `jsonb @>` szűrés minden lekérdezésen a
             | teljes klikk-táblát nézné; így a kampány-uuid indexelt oszlop.
             */
            $table->uuid('campaign_uuid')->nullable()->index();
            $table->unsignedBigInteger('journey_id')->nullable()->index();

            // A LINK azonosítója a normalizált címének kulcsolt hash-e, és maga a cím.
            $table->string('url_hash', 64);
            $table->text('url');

            /*
             | PSZEUDONIM címzett: az egyediség számolható, az azonosítás nem.
             |
             | A GDPR-törlés és a retenció után ez az ÖSSZEVONT sorok jelölője
             | (`AGGREGATED_HASH`) — a hash kulcsolt HMAC, tehát amíg a pepper a miénk,
             | személyes adatnak számít, és egy törlési kérés után nem tarthatjuk meg.
             */
            $table->string('recipient_hash', 64);

            $table->unsignedInteger('clicks')->default(1);

            /*
             | AZ EGYEDI KATTINTÓK SZÁMA — és miért kell külön oszlop.
             |
             | Élő adatnál az egyediség a SOROK száma: soronként egy (link, címzett) páros
             | áll, tehát `count(*)` a válasz, és ez az oszlop 1.
             |
             | De a golden rule 10 azt mondja, hogy „lejárat után a nyers esemény TÖRLŐDIK,
             | csak az aggregátum marad" — a nyers grain itt épp a címzett-dimenzió. A
             | retenció (és a GDPR-törlés) ezért ÖSSZEVONJA a sorokat egyetlen aggregált
             | sorba: a `clicks` az összeg, ez az oszlop pedig MEGŐRZI, hányan voltak.
             | Nélküle az összevonás után a klikk-térkép „1 egyedi kattintó"-t mutatna egy
             | ezres kampányra — a régi riport visszamenőleg hazudna.
             */
            $table->unsignedInteger('unique_clicks')->default(1);
            $table->timestampTz('first_clicked_at');
            $table->timestampTz('last_clicked_at');
            $table->timestamps();

            // AZ IDEMPOTENCIA: egy újratöltött link nem duplikál sort.
            $table->unique(['message_uuid', 'url_hash', 'recipient_hash'], 'link_clicks_unique');

            // A TÉRKÉP lekérdezése: kampány + link szerinti csoportosítás.
            $table->index(['workspace_id', 'campaign_uuid', 'url_hash'], 'link_clicks_map');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_clicks');
    }
};
