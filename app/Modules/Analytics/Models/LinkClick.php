<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Models;

use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Egy (link, címzett) páros kattintásai — a klikk-térkép sora (74. kritérium).
 *
 * ## Miért MODELL, és miért nem `DB::table`
 *
 * Mert a modell hozza a GLOBAL SCOPE-ot. A `TagStatistics` és a `ListAnalytics` esetében a
 * kézi `where('workspace_id', …)` kényszer: azok NÉZETRE és PIVOT táblára kérdeznek,
 * amiken nincs modell. Itt viszont van saját tábla — tehát nincs okunk a szűrőt a
 * fejlesztő emlékezetére bízni. Egy elfelejtett `where` egy másik ügyfél kampányának
 * klikk-térképét adná a riportba.
 *
 * ## Nincs `Auditable`, és ez tudatos
 *
 * Egy kampány klikkjei tíz- és százezres sorszámban keletkeznek, felhasználói művelet
 * nélkül. Soronkénti auditálás a törölhetetlen audit-táblát tömné meg olyan sorokkal, amik
 * nem admin-műveletek — az audit a DÖNTÉSEKET őrzi, nem a forgalmat.
 *
 * ## A `recipient_hash` PSZEUDONIM
 *
 * A kulcsolt hash-sel az egyediség számolható, az azonosítás nem. A térkép aggregált
 * nézet, nem címzett-lista: nyers címnek itt nincs funkciója, viszont egy újabb helyre
 * vinné a személyes adatot.
 */
final class LinkClick extends Model
{
    use BelongsToWorkspace;

    protected $table = 'link_clicks';

    protected $fillable = [
        'message_uuid', 'campaign_uuid', 'journey_id',
        'url_hash', 'url', 'recipient_hash',
        'clicks', 'unique_clicks', 'first_clicked_at', 'last_clicked_at',
    ];

    protected $attributes = ['clicks' => 1, 'unique_clicks' => 1];

    protected function casts(): array
    {
        return [
            'clicks' => 'integer',
            'unique_clicks' => 'integer',
            'first_clicked_at' => 'datetime',
            'last_clicked_at' => 'datetime',
        ];
    }
}
