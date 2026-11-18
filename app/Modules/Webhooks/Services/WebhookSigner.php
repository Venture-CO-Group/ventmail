<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Services;

/**
 * Webhook-aláírás: HMAC-SHA256 időbélyeggel (28. kritérium).
 *
 * Az aláírás az `időbélyeg.payload` fölött készül, nem csak a payload fölött — enélkül a
 * REPLAY támadás triviális: aki egyszer elkapta a kérést (proxy-log, félrekonfigurált
 * végpont), az évekig újraküldheti ugyanazt a „hiteles" eseményt. Az időbélyeg része az
 * aláírt adatnak, tehát nem cserélhető ki.
 *
 * A fogadó oldalnak HÁROM dolgot kell ellenőriznie: (1) az aláírás stimmel, (2) az időbélyeg
 * friss, (3) az eseményt (az ALÁÍRT törzs `id`-jét) még nem látta — a fejlécek nincsenek aláírva. A harmadik nélkül a
 * tűréshatáron belül (5 perc) az elkapott kérés újrajátszható marad — az időbélyeg-ablak
 * SZŰKÍTI a replay-t, de nem szünteti meg.
 */
final class WebhookSigner
{
    /** Ennél régebbi aláírást a fogadónak el KELL utasítania (ezt dokumentáljuk is). */
    public const TOLERANCE_SECONDS = 300;

    /**
     * Előre viszont alig tűrünk — csak annyit, amennyi óraeltérés reálisan előfordul.
     *
     * Az ablak ASZIMMETRIKUS, mert a két irány nem egyenrangú: a múltbeli tűrés a hálózati
     * késleltetést és az újrapróbálást fedi (valódi igény), a jövőbeli semmit — a saját
     * óránknál előbbre keltezett aláírás legfeljebb óracsúszás, és minden fölös másodperc
     * csak a támadó ablakát tágítja.
     */
    public const FUTURE_TOLERANCE_SECONDS = 60;

    public function sign(string $secret, string $payload, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }

    /**
     * A fogadó oldal ellenőrzése — a saját teszteinkhez és a dokumentációhoz.
     *
     * Időben állandó összehasonlítás: a naiv `===` a helyes prefix hosszából elárulná,
     * meddig találta el a támadó az aláírást.
     */
    public function verify(string $secret, string $payload, int $timestamp, string $signature): bool
    {
        $age = now()->getTimestamp() - $timestamp;

        if ($age > self::TOLERANCE_SECONDS || $age < -self::FUTURE_TOLERANCE_SECONDS) {
            return false;
        }

        return hash_equals($this->sign($secret, $payload, $timestamp), $signature);
    }
}
