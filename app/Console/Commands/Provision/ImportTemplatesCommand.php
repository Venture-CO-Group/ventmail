<?php

declare(strict_types=1);

namespace App\Console\Commands\Provision;

use App\Modules\StoredTemplates\Models\StoredTemplate;
use App\Modules\StoredTemplates\Models\StoredTemplateVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Egy verziózott sablonkészlet (`resources/mail-templates/<set>/<név>.html` + `.txt`)
 * betöltése egy workspace tárolt sablonjai közé. Idempotens: változatlan törzsre nem ír,
 * változottra új AKTÍV verziót hoz létre (a régi verzió megmarad, auditáltan inaktiválva).
 */
final class ImportTemplatesCommand extends Command
{
    use ResolvesWorkspace;

    protected $signature = 'ventmail:templates:import {workspace} {set}';

    protected $description = 'Sablonkészlet importja tárolt sablonként (idempotens, verziózott).';

    public function handle(): int
    {
        $set = (string) $this->argument('set');

        if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $set) !== 1 || ! is_dir($dir = resource_path("mail-templates/{$set}"))) {
            $this->error("Ismeretlen sablonkészlet: {$set}");

            return self::INVALID;
        }

        $this->enterWorkspace((string) $this->argument('workspace'));
        $counts = ['created' => 0, 'versioned' => 0, 'unchanged' => 0];

        foreach (glob($dir.'/*.html') ?: [] as $file) {
            $name = basename($file, '.html');
            $html = (string) file_get_contents($file);
            $text = is_file($txt = $dir.'/'.$name.'.txt') ? (string) file_get_contents($txt) : null;

            $subject = $this->subjectOf($dir.'/'.$name.'.subject');

            $counts[$this->upsert($name, $html, $text, $subject, $set)]++;
        }

        $this->info(sprintf('%s: %d új, %d új verzió, %d változatlan', $set, $counts['created'], $counts['versioned'], $counts['unchanged']));

        return self::SUCCESS;
    }

    /**
     * A `<név>.subject` fájl EGYETLEN sora a verzió tárgya (`{{ var }}` helyőrzőkkel).
     * Nincs fájl vagy üres tartalom = nincs sablon-tárgy (a kérés tárgya marad az egyetlen).
     */
    private function subjectOf(string $file): ?string
    {
        if (! is_file($file)) {
            return null;
        }

        $line = trim(preg_split('/\R/', trim((string) file_get_contents($file)))[0] ?? '');

        return $line === '' ? null : mb_substr($line, 0, 255);
    }

    private function upsert(string $name, string $html, ?string $text, ?string $subject, string $set): string
    {
        return DB::transaction(function () use ($name, $html, $text, $subject, $set): string {
            $template = StoredTemplate::query()->where('name', $name)->lockForUpdate()->first();

            if ($template === null) {
                $template = StoredTemplate::create(['name' => $name, 'description' => "{$set} (importált)"]);
                StoredTemplateVersion::create([
                    'stored_template_id' => $template->getKey(), 'version' => 1,
                    'body' => $html, 'body_text' => $text, 'subject' => $subject, 'is_active' => true,
                ]);

                return 'created';
            }

            $active = $template->versions()->where('is_active', true)->first();

            if ($active !== null && $active->body === $html && $active->body_text === $text && $active->subject === $subject) {
                return 'unchanged';
            }

            $template->versions()->where('is_active', true)->get()
                ->each(fn (StoredTemplateVersion $current) => $current->update(['is_active' => false]));

            StoredTemplateVersion::create([
                'stored_template_id' => $template->getKey(), 'version' => $template->nextVersionNumber(),
                'body' => $html, 'body_text' => $text, 'subject' => $subject, 'is_active' => true,
            ]);

            return 'versioned';
        });
    }
}
