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
 * A fogadó oldalnak ezért két dolgot kell ellenőriznie: (1) az aláírás stimmel, (2) az
 * időbélyeg friss. A második nélkül az első keveset ér.
 */
final class WebhookSigner
{
    /** Ennél régebbi aláírást a fogadónak el KELL utasítania (ezt dokumentáljuk is). */
    public const TOLERANCE_SECONDS = 300;

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
        if (abs(now()->getTimestamp() - $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        return hash_equals($this->sign($secret, $payload, $timestamp), $signature);
    }
}
