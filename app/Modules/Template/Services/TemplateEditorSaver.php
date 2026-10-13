<?php

declare(strict_types=1);

namespace App\Modules\Template\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Template\Data\TemplateStyles;
use App\Modules\Template\Models\Template;
use App\Modules\Template\Models\TemplateVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * A SZERKESZTŐ MENTÉSI ÚTJA — egy helyen, minden belépési ponthoz (autosave, mentés,
 * másolat, indulósablon, visszaállítás).
 *
 * Három ellenőrzés, ebben a sorrendben, és MIND a mentés előtt:
 *  1. a fa sémája (`BlockTreeValidator` — ugyanaz, mint a modellé és a küldő úté);
 *  2. a globális stílus (`TemplateStyles`);
 *  3. a marketing sablon KÖTELEZŐ elemei (`TemplateCompliance`: postai cím, leiratkozó,
 *     beállítások).
 *
 * A hiba MEZŐ-HIBA (`ValidationException`), nem 500: a szerkesztő ott mutatja meg, ahol a
 * felhasználó javítani tudja — és a sablon a hibás állapotban NEM íródik felül.
 */
final class TemplateEditorSaver
{
    public function __construct(
        private readonly BlockTreeValidator $validator,
        private readonly TemplateCompliance $compliance,
        private readonly TemplateVersions $versions,
        private readonly AuditLogger $audit,
        private readonly TemplateUsage $usage,
    ) {}

    /**
     * @param  array<int, mixed>  $blocks
     */
    public function assertValid(array $blocks, mixed $styles, bool $marketing): void
    {
        $errors = $this->validator->errorsIn($blocks);

        if ($errors !== []) {
            throw ValidationException::withMessages(['blocks' => $errors]);
        }

        $styleErrors = TemplateStyles::errorsIn($styles);

        if ($styleErrors !== []) {
            throw ValidationException::withMessages(['styles' => $styleErrors]);
        }

        if ($marketing) {
            $missing = $this->compliance->missing($blocks, strict: false);

            if ($missing !== []) {
                throw ValidationException::withMessages(['blocks' => [__('ventmail.editor.compliance.missing', [
                    'items' => implode(', ', array_map(static fn (string $item) => __('ventmail.editor.compliance.items.'.$item), $missing)),
                ])]]);
            }
        }
    }

    /**
     * A sablon frissítése ÉS (ha kell) új verzió.
     *
     * @param  array{name?: string, subject?: ?string, preheader?: ?string, blocks: array<int, mixed>, styles?: ?array<string, mixed>}  $data
     */
    public function save(Template $template, array $data, string $reason): ?TemplateVersion
    {
        $styles = array_key_exists('styles', $data) ? $data['styles'] : $template->styles;

        $this->assertValid($data['blocks'], $styles, (bool) $template->marketing);

        return DB::transaction(function () use ($template, $data, $styles, $reason): ?TemplateVersion {
            // A PUBLIKÁLÁSI KAPU a sor ZÁRJA ALATT (review SEC4-S1): a kontroller előellenőrzése és
            // a mentés között egy publikáló ütemezhette a sablont használó kampányt. A kampány
            // indítása / ütemezése és a journey publikálása ugyanezt a sort `FOR SHARE` zárolja,
            // tehát a kettő sorban fut: vagy a mentés látja az aktív használatot, vagy az ütemezés
            // a már mentett (és a checklisten átvizsgált) tartalmat.
            $locked = Template::query()->whereKey($template->getKey())->lockForUpdate()->firstOrFail();

            if (($denial = $this->usage->writeDenial($locked)) !== null) {
                throw new TemplatePublishLocked($denial);
            }

            // S4: az autosave (másodpercenként is jöhet) nem írja a teljes diffet az auditba.
            $template->compactAudit = $reason === 'autosave';

            try {
                $template->fill(array_filter([
                    'name' => $data['name'] ?? null,
                ], static fn ($value) => $value !== null) + [
                    'subject' => array_key_exists('subject', $data) ? $data['subject'] : $template->subject,
                    'preheader' => array_key_exists('preheader', $data) ? $data['preheader'] : $template->preheader,
                    'blocks' => $data['blocks'],
                    'styles' => $styles === [] ? null : $styles,
                ])->save();
            } catch (InvalidArgumentException $e) {
                // A MODELL saját szabálya (leiratkozó blokk) — mező-hiba, nem 500.
                throw ValidationException::withMessages(['blocks' => [$e->getMessage()]]);
            } finally {
                $template->compactAudit = false;
            }

            $version = $this->versions->snapshot($template, $reason);

            if ($reason === 'autosave') {
                $this->audit->log('template.autosaved', $template, context: [
                    'render_hash' => (string) $template->render_hash,
                    'blocks' => count((array) $template->blocks),
                    'version' => $version?->number,
                ]);
            }

            return $version;
        });
    }
}
