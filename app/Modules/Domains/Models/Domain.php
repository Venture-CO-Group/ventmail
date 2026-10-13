<?php

declare(strict_types=1);

namespace App\Modules\Domains\Models;

use App\Modules\Audit\Concerns\Auditable;
use App\Modules\Domains\Services\DnsRecordGenerator;
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
        'verified_at', 'unverified_at', 'last_checked_at', 'dkim_active_selector',
        'return_path_status', 'return_path_marketing_status',
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
        'return_path_status' => 'pending',
        'return_path_marketing_status' => 'pending',
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
            'unverified_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'dkim_rotated_at' => 'datetime',
        ];
    }

    /**
     * A wizardban megjelenő ÉS a DNS-check által ellenőrzött rekordok — ugyanabból a
     * forrásból, hogy ne csúszhassanak szét.
     *
     * @return array<string, array{type: string, name: string, value: string, purpose: string}>
     */
    public function dnsRecords(): array
    {
        return app(DnsRecordGenerator::class)->for($this);
    }

    /**
     * Az ALÁÍRÁSHOZ használandó selector.
     *
     * Rotáció alatt még a RÉGI: az új selector DNS-rekordja ekkor még nem látszik, tehát
     * az új kulccsal aláírt levelek DKIM-je bukna — pont az, amit az átfedés elkerülni hivatott.
     */
    public function signingSelector(): ?string
    {
        return $this->dkim_active_selector ?? $this->dkim_selector;
    }

    /** Az előző DKIM-selector még érvényes-e (rotációs átfedés). */
    public function previousDkimIsValid(): bool
    {
        if ($this->dkim_previous_selector === null || $this->dkim_rotated_at === null) {
            return false;
        }

        return $this->dkim_rotated_at->addDays((int) config('domains.dkim_rotation_overlap_days'))->isFuture();
    }

    public function isVerified(): bool
    {
        return $this->status === 'verified';
    }
}
