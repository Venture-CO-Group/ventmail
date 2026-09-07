<?php

declare(strict_types=1);

namespace App\Modules\Validation\Data;

/** Egy mailbox-szonda eredménye. */
final readonly class ProbeResult
{
    private function __construct(
        public string $status,
        public ?int $code = null,
        public ?string $detail = null,
    ) {}

    /** A fogadó elfogadta a `RCPT TO`-t: a postafiók létezik. */
    public static function accepted(int $code = 250): self
    {
        return new self('accepted', $code);
    }

    /** A fogadó elutasította: a cím nem létezik (5.1.1). */
    public static function rejected(int $code, string $detail = ''): self
    {
        return new self('rejected', $code, $detail);
    }

    /**
     * A fogadó MINDENT elfogad — catch-all.
     *
     * Ez nem „létezik": azt jelenti, hogy a szerver nem árulja el. A cím lehet jó és rossz
     * is, és a különbséget csak a tényleges küldés mutatná meg.
     */
    public static function catchAll(): self
    {
        return new self('catch_all');
    }

    /** Nem tudtuk megkérdezni (greylisting, időtúllépés, tiltás). */
    public static function unknown(string $detail = ''): self
    {
        return new self('unknown', null, $detail);
    }

    /**
     * NEM KÉRDEZTÜNK — és ez nem ugyanaz, mint a bizonytalanság.
     *
     * A küldési úton szándékosan nem szondázunk (a latencia miatt). Ha ezt `unknown`-nak
     * könyvelnénk, minden címzett kapna egy alapkockázatot a MI döntésünk miatt.
     */
    public static function skipped(): self
    {
        return new self('skipped');
    }
}
