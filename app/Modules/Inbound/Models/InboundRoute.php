<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Models;

use App\Modules\Audit\Concerns\Auditable;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Egy bejövő route-szabály (30. kritérium).
 *
 * A SORREND a szabály lelke: prioritás szerint, azon belül `id` szerint. Az azonos
 * prioritásúak közti `id`-rendezés nem kozmetika — enélkül a kiértékelés a Postgres
 * pillanatnyi kedvétől függne, és ugyanaz a levél ma máshova menne, mint holnap.
 */
final class InboundRoute extends Model
{
    use Auditable, BelongsToWorkspace, HasUuids, SoftDeletes;

    public const MATCH_TYPES = ['match_recipient', 'match_header', 'catch_all'];

    public const ACTION_TYPES = ['forward', 'store', 'stop'];

    protected $fillable = ['description', 'priority', 'match_type', 'match_field', 'expression', 'actions', 'active'];

    protected $attributes = ['priority' => 100, 'active' => true, 'actions' => '[]'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'actions' => 'array',
            'active' => 'boolean',
            'priority' => 'integer',
            'last_matched_at' => 'datetime',
        ];
    }

    public function messages(): mixed
    {
        return $this->hasMany(InboundMessage::class);
    }
}
