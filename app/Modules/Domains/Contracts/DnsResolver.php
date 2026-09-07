<?php

declare(strict_types=1);

namespace App\Modules\Domains\Contracts;

/**
 * DNS-lekérdezés — a hitelesítés egyetlen külső függősége.
 *
 * Interfész mögött, mert (a) a teszt determinisztikus kell legyen valódi DNS nélkül, és
 * (b) élesben cserélhető legyen több resolverre (rekurzív, DoH) anélkül, hogy a
 * hitelesítési logika változna.
 */
interface DnsResolver
{
    /**
     * @return list<string> a megtalált rekordértékek (üres tömb, ha nincs ilyen rekord)
     *
     * @throws DnsUnavailable ha a LEKÉRDEZÉS nem futott le
     */
    public function records(string $name, string $type): array;
}
