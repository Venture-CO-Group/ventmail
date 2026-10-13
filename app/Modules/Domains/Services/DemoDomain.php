<?php

declare(strict_types=1);

namespace App\Modules\Domains\Services;

use App\Modules\Domains\Models\Domain;

/**
 * A DEV-SEEDEK közös demó-domainje (`pelda.hu`) — KIZÁRÓLAG a helyi/teszt dev-seed végpontokhoz.
 *
 * D292: a domain `verified` (a PreSendGate 1. kapuja ezt kéri a demó-küldésekhez), ezért a rekordjai
 * is rendben vannak — korábban a felület „Rendben" jelvényt mutatott „0 / 5 rekord rendben" mellett.
 * Idempotens: egy régebbi, ellentmondásos demó-domaint is rendbe tesz. A tracking CNAME-et nem
 * kapcsolja be (az a demó-analitika saját döntése), csak az állapotát igazítja.
 */
final class DemoDomain
{
    public const NAME = 'pelda.hu';

    public static function ensure(): Domain
    {
        $domain = Domain::query()->firstOrCreate(['name' => self::NAME], ['status' => 'verified', 'verified_at' => now()]);

        $domain->forceFill([
            'status' => 'verified',
            'verified_at' => $domain->verified_at ?? now(),
            'spf_status' => 'verified',
            'dkim_status' => 'verified',
            'dmarc_status' => 'verified',
            'return_path_status' => 'verified',
            'return_path_marketing_status' => 'verified',
            'tracking_status' => 'verified',
            'last_checked_at' => now(),
        ]);

        if ($domain->isDirty()) {
            $domain->save();
        }

        return $domain;
    }
}
