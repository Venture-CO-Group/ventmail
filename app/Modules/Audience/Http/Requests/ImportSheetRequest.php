<?php

declare(strict_types=1);

namespace App\Modules\Audience\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** XLSX: a kiválasztott munkalap neve. */
final class ImportSheetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('audience.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['sheet' => ['required', 'string', 'max:255']];
    }
}
