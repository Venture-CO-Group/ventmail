<?php

declare(strict_types=1);

namespace App\Modules\Sending\Data;

/** Egy driver-hívás eredménye. */
final readonly class DispatchResult
{
    public function __construct(
        public bool $accepted,
        public string $providerMessageId,
        public ?string $error = null,
        /**
         * A driver a KÉZBESÍTÉST is visszaigazolta.
         *
         * Egy valódi MTA 2xx-e csak annyit jelent, hogy az üzenet sorba állt — a
         * `delivered` később, a webhookból jön (A3). Ha ezt itt azonnal `delivered`-nek
         * vennénk, a kézbesíthetőségi statisztika az első naptól hamis lenne.
         */
        public bool $delivered = false,
        /**
         * Azok a címzettek, akiket az MTA MÁR ÁTVETT.
         *
         * Batch-küldésnél a driver címzettenként hív (a személyre szabás miatt), tehát egy
         * félbeszakadt küldésnél a lista eleje már kiment. Enélkül az újrapróbálás nekik
         * MÁSODSZOR is kézbesítene, a trace pedig mindenkire `deferred`-et írna — azokra
         * is, akik megkapták.
         *
         * @var list<string>
         */
        public array $acceptedRecipients = [],
        /**
         * A fogadó VÁLASZA, ha van: kód + enhanced + szöveg.
         *
         * Enélkül a bounce-osztályozó vak: a `deferred`-ből nem derülne ki, hogy a cím nem
         * létezik (hard, azonnali suppression) vagy csak tele a postafiók (soft).
         */
        public ?int $code = null,
        public ?string $enhancedCode = null,
        public ?string $response = null,
        /**
         * A VALÓDI SMTP-párbeszéd sorai, ha a driver látja őket.
         *
         * A 23–24. kritérium ezt kéri a trace-be: a support a fogadó szerver saját
         * szavaiból tudja megmondani, miért nem ment át a levél. API-alapú MTA-nál (Postal
         * HTTP) ez üres marad — ott a kód és a szöveg az, amink van.
         *
         * A sorok NYERSEN érkeznek, és a naplózó redaktálja őket: a hitelesítési sor
         * base64-je maga a jelszó.
         *
         * CÍMZETTENKÉNT kulcsolva. Egyetlen közös átirattal „A" törlése után „B" naplósorában
         * ott maradna a `RCPT TO:<A>` — a GDPR-törlés csendben hiányos lenne.
         *
         * @var array<string, list<array{direction: string, line: string}>>
         */
        public array $transcript = [],
    ) {}

    /** Az MTA átvette (de még nem kézbesítette). */
    public static function accepted(string $providerMessageId): self
    {
        return new self(true, $providerMessageId);
    }

    /** A driver a kézbesítést is visszaigazolta (fake/log driver, vagy szinkron MTA). */
    public static function delivered(string $providerMessageId): self
    {
        return new self(true, $providerMessageId, delivered: true);
    }

    public static function failed(string $error, ?int $code = null, ?string $response = null, ?string $enhanced = null): self
    {
        return new self(false, '', $error, code: $code, enhancedCode: $enhanced, response: $response ?? $error);
    }

    /**
     * Részben sikerült küldés: a felsorolt címzettek MÁR megkapták, a többi újrapróbálandó.
     *
     * @param  list<string>  $acceptedRecipients
     */
    public static function partial(string $error, array $acceptedRecipients, ?int $code = null, ?string $response = null): self
    {
        return new self(false, '', $error, acceptedRecipients: $acceptedRecipients, code: $code, response: $response ?? $error);
    }
}
