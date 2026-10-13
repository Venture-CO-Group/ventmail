<?php

declare(strict_types=1);

namespace App\Modules\Audience\Http\Requests;

use App\Modules\Audience\Models\ContactField;
use App\Modules\Audience\Services\Import\ColumnSuggester;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** Az oszlop-leképezés mentése: pontosan egy e-mail, zárt célok, egy cél csak egyszer. */
final class ImportMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('audience.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mapping' => ['required', 'array', 'min:1', 'max:'.(int) config('audience.import.max_columns')],
            'mapping.*' => ['required', 'string', 'max:60'],
            'hasHeader' => ['required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('mapping') || $validator->errors()->has('mapping.*')) {
                    return;
                }

                $allowed = array_merge(
                    ['skip', 'email', 'first_name', 'last_name'],
                    array_map(
                        static fn (string $key): string => 'attr:'.$key,
                        array_merge(ColumnSuggester::BUILTIN_ATTRIBUTES, ContactField::query()->pluck('key')->all()),
                    ),
                );

                $used = [];

                foreach ((array) $this->input('mapping') as $column => $target) {
                    $target = (string) $target;

                    if (! ctype_digit((string) $column) || ! in_array($target, $allowed, true)) {
                        $validator->errors()->add('mapping', (string) __('ventmail.audience.import.mappingUnknown'));

                        return;
                    }

                    if ($target !== 'skip' && isset($used[$target])) {
                        $validator->errors()->add('mapping', (string) __('ventmail.audience.import.mappingDuplicate'));

                        return;
                    }

                    $used[$target] = true;
                }

                if (! isset($used['email'])) {
                    $validator->errors()->add('mapping', (string) __('ventmail.audience.import.mappingNoEmail'));
                }
            },
        ];
    }
}
