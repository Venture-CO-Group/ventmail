<?php

declare(strict_types=1);

namespace App\Modules\Deliverability\Data;

/**
 * Egy seed-postafiók: a cím ÉS a szolgáltató, akihez tartozik.
 *
 * A szolgáltató-címke a LISTA tulajdonsága, nem a mérésé, és ez a különbség számít: ha a
 * megérkezett levelet a jelentés címkéjével (`Gmail`), a hiányzót viszont a cím domainjével
 * (`gmail.com`) könyvelnénk, ugyanaz a szolgáltató KÉT vödörbe kerülne — és a
 * „szolgáltatónkénti romlás" pont ott nem látszana, ahol a baj van.
 */
final readonly class SeedAddress
{
    public function __construct(
        public string $address,
        public string $provider,
    ) {}
}
