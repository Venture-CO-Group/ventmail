<?php

declare(strict_types=1);

namespace App\Modules\Domains\Models;

use App\Modules\Audit\Concerns\Auditable;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Küldő domain és hitelesítési állapota (spec 4.).
 *
 * A PreSendGate 1. kapuja ezt olvassa: verifikálatlan domainről nincs küldés
 * (golden rule 8 — „deliverability nem alku").
 */
final class Domain extends Model
{
    use Auditable, BelongsToWorkspace, HasUuids, SoftDeletes;

    protected $fillable = [
        'name', 'status', 'dkim_selector', 'dkim_public_key', 'dkim_private_key',
        'dkim_previous_selector', 'dkim_previous_public_key', 'dkim_previous_private_key',
        'dkim_rotated_at', 'tracking_domain', 'tracking_status',
        'spf_status', 'dkim_status', 'dmarc_status', 'ptr_status', 'mta_sts_status',
        'bimi_status', 'inbound_mx_status', 'tracking_enabled', 'require_tls',
        'verified_at', 'last_checked_at',
    ];

    /** A privát kulcs sosem kerül szerializálva a válaszba. */
    protected $hidden = ['dkim_private_key', 'dkim_previous_private_key'];

    /**
     * A DB-alapértékek modell-szinten is jelen vannak: enélkül az audit `before` mezője
     * üres lenne az első módosításkor (a modell nem ismerné a kezdeti állapotot).
     */
    protected $attributes = [
        'status' => 'pending',
        'spf_status' => 'pending',
        'dkim_status' => 'pending',
        'dmarc_status' => 'pending',
        'ptr_status' => 'pending',
        'mta_sts_status' => 'disabled',
        'bimi_status' => 'disabled',
        'tracking_status' => 'pending',
        'inbound_mx_status' => 'disabled',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'dkim_private_key' => 'encrypted',
            'dkim_previous_private_key' => 'encrypted',
            'tracking_enabled' => 'boolean',
            'require_tls' => 'boolean',
            'verified_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'dkim_rotated_at' => 'datetime',
        ];
    }

    public function isVerified(): bool
    {
        return $this->status === 'verified';
    }
}
