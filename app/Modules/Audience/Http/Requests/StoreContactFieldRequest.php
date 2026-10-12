<?php

declare(strict_types=1);

namespace App\Modules\Audience\Http\Requests;

use App\Modules\Audience\Models\ContactField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Egyéni mező felvétele a varázslóból: zárt kulcs-alak, foglalt nevek nélkül, tenantonként egyedi. */
final class StoreContactFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('audience.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // JSON-útvonalként kerül a szegmens-lekérdezésbe: idézőjel és szóköz nem lehet benne.
            'key' => [
                'required', 'string', 'regex:/^[a-z][a-z0-9_]{0,39}$/',
                Rule::notIn(ContactField::RESERVED),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    // A mezők száma korlátozott: a kulcsok a szegmens-szerkesztő listáját és az
                    // attribútum-JSON-t növelik, korlát nélkül egy tag felfújhatná.
                    if (ContactField::query()->count() >= (int) config('audience.import.max_fields')) {
                        $fail((string) __('ventmail.audience.import.fieldLimit'));

                        return;
                    }

                    if (ContactField::query()->where('key', $value)->exists()) {
                        $fail((string) __('ventmail.audience.import.fieldExists'));
                    }
                },
            ],
            'label' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(ContactField::TYPES)],
        ];
    }
}
