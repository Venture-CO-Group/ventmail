<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Models;

use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * WORKSPACE-BRANDING a PDF-riport borítójához és láblécéhez (v1.1 · 8. modul, D233).
 *
 * Workspace-enként legfeljebb egy sor; hiányzó mező = Venture-alapértelmezés. A sub-account nem
 * örököl. Az írásokat a `BrandingController` explicit, nevesített audit-sorral naplózza
 * (`branding.updated`, `branding.logo_uploaded`, `branding.logo_removed`).
 */
final class WorkspaceBranding extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'primary_color', 'company_name', 'footer_text',
        'logo_path', 'logo_mime', 'logo_width', 'logo_height', 'logo_size', 'logo_sha256', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['logo_width' => 'integer', 'logo_height' => 'integer', 'logo_size' => 'integer'];
    }

    public function hasLogo(): bool
    {
        return $this->logo_path !== null && $this->logo_path !== '';
    }
}
