<?php

declare(strict_types=1);

namespace App\Modules\StoredTemplates\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** Új sablonverzió a felületről: törzs, szöveges változat, tárgy, azonnali aktiválás. */
final class StoreVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('storedTemplate.manage');
    }

    /**
     * A tárgy egyetlen sor: a sortörés fejléc-injekció lenne, ezért validálás előtt
     * szóközre cserélődik (nem elutasítjuk — egy beillesztett sortörés nem támadás).
     */
    protected function prepareForValidation(): void
    {
        $subject = $this->input('subject');

        if (is_string($subject)) {
            $this->merge(['subject' => trim((string) preg_replace('/\s*[\r\n]+\s*/', ' ', $subject))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:1048576'],
            'body_text' => ['nullable', 'string', 'max:1048576'],
            'subject' => ['nullable', 'string', 'max:255'],
            'activate' => ['sometimes', 'boolean'],
        ];
    }
}
