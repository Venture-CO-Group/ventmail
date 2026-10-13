<?php

declare(strict_types=1);

namespace App\Modules\Audience\Http\Requests;

use App\Modules\Audience\Enums\ImportLegalBasis;
use App\Modules\Audience\Models\ContactList;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Az import 3. lépése: cél lista, címkék, frissítési mód, és a KÖTELEZŐ jogalap.
 *
 * ## A jogalap kötelezősége
 *
 * A `legalBasis` MINDIG kötelező. A forrás és a dátum minden olyan jogalapnál kötelező, ahol
 * van mit igazolni (`verified_consent`, `legitimate_interest`); csak az `unknown` mentesít —
 * az éppen azt mondja, hogy nincs forrás. A „subscribed" eredmény-státusz a három együttes
 * megléte (`ImportLegalBasis::resultingStatus`), nem a felhasználó közvetlen választása.
 */
final class ImportSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('audience.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'listUuid' => ['nullable', 'uuid', function (string $attribute, mixed $value, \Closure $fail): void {
                // A lista a SAJÁT workspace-é kell legyen: a modell global scope-ja szűr.
                if (! ContactList::query()->where('uuid', $value)->exists()) {
                    $fail((string) __('ventmail.audience.import.listUnknown'));
                }
            }],
            'tags' => ['nullable', 'array', 'max:20'],
            // `required`: a levágás utáni üres címke (a TrimStrings null-ra állítja) nem megy át csendben.
            'tags.*' => ['required', 'string', 'max:60'],
            'updateMode' => ['required', Rule::in(['skip', 'fill', 'overwrite'])],
            'legalBasis' => ['required', Rule::enum(ImportLegalBasis::class)],
            'consentSource' => ['required_unless:legalBasis,unknown', 'nullable', 'string', 'max:255'],
            // Alsó határ: egy `0001-01-01` hozzájárulás-dátum értelmetlen bizonyíték (és az `after` az
            // ismeretlen jogalapnál megadott dátumot is fogja).
            'consentDate' => ['required_unless:legalBasis,unknown', 'nullable', 'date_format:Y-m-d', 'after_or_equal:1990-01-01', 'before_or_equal:today'],
            'rolePolicy' => ['nullable', Rule::in(['skip', 'import'])],
            'disposablePolicy' => ['nullable', Rule::in(['skip', 'import'])],
        ];
    }
}
