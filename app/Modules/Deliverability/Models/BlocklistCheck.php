<?php

declare(strict_types=1);

namespace App\Modules\Deliverability\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Egy blocklist-ellenőrzés eredménye.
 *
 * SZÁNDÉKOSAN nincs `workspace_id`: a küldő IP a MI infrastruktúránk, a találat pedig MINDEN
 * tenantot érint, aki arról az IP-ről küld. Tenant-adatnak könyvelve az egyik ügyfél nem
 * látná, hogy a másik forgalma miatt került listára a közös IP — pedig épp őt sújtja.
 */
final class BlocklistCheck extends Model
{
    protected $fillable = ['ip_address_id', 'target', 'zone', 'listed', 'detail', 'checked_at'];

    protected function casts(): array
    {
        return ['listed' => 'boolean', 'checked_at' => 'datetime'];
    }
}
