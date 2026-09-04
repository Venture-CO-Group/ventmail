<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Contracts;

/**
 * A NYERS levél és a csatolmányok tárolója (külső gazda: Wasabi EU).
 *
 * Interfész mögött, mert külső gazda — a fake a teszttel fedett kontraktus. A modul sosem
 * ismeri az S3 belsőségeit, és a tárolócsere config, nem domain-kód.
 *
 * A `temporaryUrl` SZÁNDÉKOSAN lejáró: egy csatolmány idegen tartalom, amit egy harmadik fél
 * küldött. Örökre élő, kitalálható URL-lel a fájltárunk nyilvános szemétdombbá válna.
 */
interface RawMailStore
{
    /** @return string a tárolókulcs */
    public function put(string $key, string $contents): string;

    public function get(string $key): ?string;

    public function delete(string $key): void;

    /** Aláírt, LEJÁRÓ letöltési URL. */
    public function temporaryUrl(string $key, int $seconds): string;
}
