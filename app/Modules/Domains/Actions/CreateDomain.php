<?php

declare(strict_types=1);

namespace App\Modules\Domains\Actions;

use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Services\DkimKeyManager;
use App\Modules\Sending\Services\DomainNormalizer;

/**
 * Domain felvétele a wizard első lépéseként.
 *
 * A DKIM kulcspár MOST keletkezik: a wizard a publikus kulcsot azonnal meg tudja mutatni,
 * a privát pedig titkosítva marad az adatbázisban.
 */
final class CreateDomain
{
    public function __construct(private readonly DkimKeyManager $dkim) {}

    public function __invoke(string $name): Domain
    {
        $keys = $this->dkim->generate();

        return Domain::create([
            'name' => DomainNormalizer::canonical($name),
            'dkim_selector' => $keys['selector'],
            'dkim_private_key' => $keys['private'],
            'dkim_public_key' => $keys['public'],
        ]);
    }
}
