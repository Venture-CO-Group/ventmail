<?php

declare(strict_types=1);

namespace App\Modules\Template\Models;

use App\Modules\Audit\Concerns\Auditable;
use App\Modules\StoredTemplates\Models\StoredTemplate;
use App\Modules\Template\Data\TemplateStyles;
use App\Modules\Template\Services\BlockTreeValidator;
use App\Modules\Template\Services\MjmlCompiler;
use App\Modules\Template\Services\MjmlWriter;
use App\Modules\Template\Services\PlainTextWriter;
use App\Modules\Tenancy\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

/**
 * Egy block editor sablon (58–61., 67. kritérium).
 *
 * ## AZ IGAZSÁG A BLOCK-TREE
 *
 * A HTML és a plaintext ebből SZÁRMAZIK, és gyorsítótárazva tárolódik. A block-tree a
 * ventStudio-val KÖZÖS JSON Schema ellen validál — ez az alak az, amit két app is ért. Ha a
 * HTML lenne az igazság, a szerkesztő nem tudná visszaolvasni; ha mindkettő szerkeszthető
 * lenne, két igazság élne egymás mellett, és a következő fordítás az egyiket elnyelné.
 *
 * ## A VALIDÁLÁS ÉS A LEIRATKOZÓ SZABÁLY MENTÉSKOR FUT
 *
 * Nem a küldéskor, és nem a felületen. A felületi ellenőrzés megkerülhető (API-hívás), a
 * küldéskori pedig túl késő: a felhasználó azt hinné, hogy a sablonja rendben van, és a
 * hibát a kampány indításakor tudná meg — vagy még később, a kapu elutasításából.
 */
final class Template extends Model
{
    use Auditable, BelongsToWorkspace, HasUuids, SoftDeletes;

    protected $fillable = ['name', 'subject', 'preheader', 'blocks', 'marketing', 'styles', 'starter_key'];

    protected $attributes = ['blocks' => '[]', 'marketing' => true];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'blocks' => 'array',
            'styles' => 'array',
            'marketing' => 'boolean',
            'rendered_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $template): void {
            $blocks = (array) $template->blocks;

            // 58. — a séma ellen validál, KIVÉTELLEL. A csendes tisztítás azt jelentené,
            // hogy a szerkesztőből eltűnik egy szakasz, a felhasználó viszont sikert lát.
            app(BlockTreeValidator::class)->validate($blocks);

            // v1.1 — a GLOBÁLIS stílus is típusos (lásd `TemplateStyles`).
            $styleErrors = TemplateStyles::errorsIn($template->styles);

            if ($styleErrors !== []) {
                throw new InvalidArgumentException(implode(' | ', $styleErrors));
            }

            // 61. — MARKETING sablonban a leiratkozó blokk KÖTELEZŐ, a mentés blokkolva
            // nélküle.
            //
            // Miért a mentésnél, és miért nem a küldésnél: a küldéskori ellenőrzés a kapu
            // dolga (`UnsubscribeHeaderGate`, A6), és az a FEJLÉCET nézi. A levél
            // TÖRZSÉBEN lévő link ettől független követelmény — a Gmail és a Yahoo
            // mindkettőt elvárja —, és ha csak a küldés utasítaná el, a felhasználó egy
            // elkészült, ütemezett kampányról tudná meg, hogy használhatatlan.
            if ($template->marketing && ! self::containsUnsubscribe($blocks)) {
                throw new InvalidArgumentException(
                    'Marketing sablonban KÖTELEZŐ a leiratkozó blokk (61. kritérium): a Gmail és a '
                    .'Yahoo 2024 februárja óta megköveteli, és a hiánya nem rosszabb kézbesítés, hanem '
                    .'elutasítás. Adj hozzá egy `unsubscribe` blokkot — vagy vedd ki a sablon '
                    .'marketing jelölését.'
                );
            }

            // A FORDÍTÁS gyorsítótára a mentés része: így a küldési út nem fordít, és a
            // felület a mentés után azonnal az érvényes HTML-t mutatja.
            $template->recompile();
        });
    }

    /** A gyorsítótár újraépítése — az igazság mindig a `blocks`. */
    public function recompile(): void
    {
        $blocks = (array) $this->blocks;

        $styles = is_array($this->styles) ? $this->styles : null;

        $mjml = app(MjmlWriter::class)->write($blocks, $this->preheader, $styles);

        $this->attributes['mjml'] = $mjml;
        $this->attributes['html'] = app(MjmlCompiler::class)->compile($mjml);
        $this->attributes['text'] = app(PlainTextWriter::class)->write($blocks, $this->preheader);

        // A LENYOMAT a determinizmus őre: ugyanaz a fa ugyanazt a HTML-t adja. A
        // snapshot-teszt (59. kritérium) ezt méri, és egy fordító-változás itt bukik el —
        // nem az ügyfél postafiókjában.
        $this->attributes['render_hash'] = hash('sha256', $mjml.'|'.$this->attributes['html'].'|'.$this->attributes['text']);
        $this->attributes['rendered_at'] = now();
    }

    /**
     * VAN a fában leiratkozó blokk?
     *
     * A `unsubscribe` KÜLÖN blokktípus, nem „egy text blokk linkkel" — pontosan azért, hogy
     * ez a szabály kikényszeríthető legyen. Egy szöveges link keresése URL-minta alapján
     * ugyanabba a csapdába vezetne, amibe az A6 leiratkozó fejléce négy körön át beleesett:
     * szöveget keresni egy szabadon írható tartalomban nem tehető teljessé.
     *
     * @param  array<int, mixed>  $blocks
     */
    public static function containsUnsubscribe(array $blocks): bool
    {
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            // A LÁBLÉC (v1.1) a leiratkozó és a beállítás-linket KÖTELEZŐEN kiírja — ugyanaz a
            // felismerhető elem, csak a postai címmel együtt.
            if (in_array($block['type'] ?? null, ['unsubscribe', 'footer'], true)) {
                return true;
            }

            if (self::containsUnsubscribe((array) ($block['children'] ?? []))) {
                return true;
            }
        }

        return false;
    }

    public function storedTemplate(): BelongsTo
    {
        return $this->belongsTo(StoredTemplate::class);
    }

    /** @return HasMany<TemplateVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(TemplateVersion::class);
    }
}
