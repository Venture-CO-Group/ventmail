<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Próbaentitás a tenant-izoláció teszteléséhez: pontosan úgy viselkedik, ahogy egy valódi
 * workspace-hez kötött modell fog (M0-2-től kezdve mindegyik ilyen lesz).
 */
final class TenantFixture extends Model
{
    use BelongsToWorkspace;

    protected $table = 'tenant_fixtures';

    protected $fillable = ['label', 'workspace_id'];

    public static function createTable(): void
    {
        if (Schema::hasTable('tenant_fixtures')) {
            return;
        }

        Schema::create('tenant_fixtures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->timestamps();
        });
    }
}
