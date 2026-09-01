<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Szándékosan üres.
     *
     * Felhasználót NEM seedelünk: a `users` sor a ventSSO `sub` claimjéhez kötött tükör,
     * és egy előre beseedelt e-mail cím pre-registration account takeover-t engedne, ha a
     * párosítás valaha e-mail alapúvá csúszna. A tenant-fixtúrákat az M0-1 hozza,
     * `sso_subject` alapon.
     */
    public function run(): void
    {
        //
    }
}
