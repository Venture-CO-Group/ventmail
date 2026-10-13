<?php

declare(strict_types=1);

namespace App\Modules\Template\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Template\Models\Template;
use App\Modules\Template\Models\TemplateVersion;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * VERZIÓTÖRTÉNET (v1.1).
 *
 * ## Mikor születik verzió
 *
 * - KÉZI mentésnél (`save`), létrehozáskor (`create`) és visszaállításnál (`restore`) mindig;
 * - AUTOMATIKUS mentésnél csak akkor, ha az utolsó verzió régebbi, mint `AUTOSAVE_INTERVAL`.
 *   A debounce-olt autosave másodpercenként írhat — ha mindegyik verzió lenne, a történet
 *   egy olvashatatlan, ezersoros lista lenne, ami épp a visszaállítást tenné használhatatlanná.
 *
 * Azonos tartalom (`content_hash`) nem hoz új verziót: a „mentés" kétszer egymás után nem
 * két bejegyzés.
 *
 * ## Felső korlát
 *
 * Sablononként `MAX_VERSIONS`; a legrégebbiek esnek ki. A verzió pillanatkép, nem napló — az
 * auditnapló a változás TÉNYÉT úgyis megőrzi.
 */
final class TemplateVersions
{
    public const AUTOSAVE_INTERVAL_SECONDS = 600;

    public const MAX_VERSIONS = 100;

    public function __construct(private readonly AuditLogger $audit) {}

    public function snapshot(Template $template, string $reason = 'save', ?int $restoredFrom = null): ?TemplateVersion
    {
        return DB::transaction(function () use ($template, $reason, $restoredFrom): ?TemplateVersion {
            // A SOR-ZÁR a sorszám versenyét zárja ki: két párhuzamos mentés ne kapja ugyanazt.
            Template::query()->whereKey($template->getKey())->lockForUpdate()->first();

            $latest = TemplateVersion::query()
                ->where('template_id', $template->getKey())
                ->orderByDesc('number')
                ->first();

            $hash = self::hash($template);

            if ($latest !== null && $latest->content_hash === $hash && $reason !== 'restore') {
                return null;
            }

            if ($reason === 'autosave' && $latest !== null
                && $latest->created_at !== null
                && $latest->created_at->diffInSeconds(now()) < self::AUTOSAVE_INTERVAL_SECONDS) {
                return null;
            }

            $version = TemplateVersion::query()->create([
                'template_id' => $template->getKey(),
                'number' => ($latest?->number ?? 0) + 1,
                'name' => (string) $template->name,
                'subject' => $template->subject,
                'preheader' => $template->preheader,
                'blocks' => (array) $template->blocks,
                'styles' => $template->styles,
                'reason' => $reason,
                'restored_from' => $restoredFrom,
                'content_hash' => $hash,
                'created_by' => Auth::id(),
            ]);

            $this->prune($template);

            return $version;
        });
    }

    /**
     * VISSZAÁLLÍTÁS: a régi tartalom a sablonra kerül, és ÚJ verzió születik belőle.
     */
    public function restore(Template $template, TemplateVersion $version): TemplateVersion
    {
        return DB::transaction(function () use ($template, $version): TemplateVersion {
            // A visszaállítás is ÍRÁS: a publikálási kapu a sor zárja alatt (review SEC4-S1).
            $locked = Template::query()->whereKey($template->getKey())->lockForUpdate()->firstOrFail();

            if (($denial = app(TemplateUsage::class)->writeDenial($locked)) !== null) {
                throw new TemplatePublishLocked($denial);
            }

            $template->fill([
                'subject' => $version->subject,
                'preheader' => $version->preheader,
                'blocks' => (array) $version->blocks,
                'styles' => $version->styles,
            ])->save();

            $restored = $this->snapshot($template, 'restore', $version->number);

            $this->audit->log('template.version_restored', $template, context: [
                'restored_from' => $version->number,
                'new_version' => $restored?->number,
            ]);

            /** @var TemplateVersion $restored */
            return $restored;
        });
    }

    public static function hash(Template $template): string
    {
        return hash('sha256', (string) json_encode([
            $template->subject,
            $template->preheader,
            (array) $template->blocks,
            $template->styles,
        ]));
    }

    private function prune(Template $template): void
    {
        $keep = TemplateVersion::query()
            ->where('template_id', $template->getKey())
            ->orderByDesc('number')
            ->limit(self::MAX_VERSIONS)
            ->pluck('id');

        // A pillanatképet NEM módosítjuk, csak a felső korláton túliakat töröljük — a
        // `deleted` audit-sor a tényt megőrzi.
        TemplateVersion::query()
            ->where('template_id', $template->getKey())
            ->whereNotIn('id', $keep)
            ->get()
            ->each->delete();
    }
}
