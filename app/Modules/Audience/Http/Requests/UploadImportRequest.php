<?php

declare(strict_types=1);

namespace App\Modules\Audience\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * Az import 1. lépése: a három bevitel (fájl, beillesztett lista, kézi egyedi) validálása.
 *
 * A fájl-ellenőrzés három rétegű, és mindhárom a TARTALOMRA megy, nem a kliens állításaira:
 * méret (a konfigurált plafon), kiterjesztés (csv/txt/xlsx), és a tartalom „varázsszáma"
 * (az XLSX zip-fejléc, a CSV nem bináris). A zip-bomba és a sor/oszlop-határ a felmérésnél
 * dől el (`ImportFileInspector`), mert ahhoz már olvasni kell.
 */
final class UploadImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('audience.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mode' => ['required', 'in:file,paste,manual'],
            'file' => [
                'required_if:mode,file', 'nullable', 'file', 'extensions:csv,txt,xlsx',
                // BÁJT-pontos plafon (a `max:` kilobájtra kerekít, és az alsó határ így átcsúszna).
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value instanceof UploadedFile && $value->getSize() > (int) config('audience.import.max_bytes')) {
                        $fail((string) __('ventmail.audience.import.limit.size'));
                    }
                },
            ],
            'emails' => ['required_if:mode,paste', 'nullable', 'string', 'max:2000000'],
            'email' => ['required_if:mode,manual', 'nullable', 'string', 'max:254', 'email:filter'],
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $file = $this->file('file');

                if ($this->input('mode') !== 'file' || ! $file instanceof UploadedFile || $validator->errors()->has('file')) {
                    return;
                }

                $head = (string) file_get_contents((string) $file->getRealPath(), false, null, 0, 8192);
                $extension = strtolower($file->getClientOriginalExtension());

                if ($extension !== 'xlsx' && (str_starts_with($head, "\xFF\xFE") || str_starts_with($head, "\xFE\xFF"))) {
                    $validator->errors()->add('file', (string) __('ventmail.audience.import.limit.encoding'));

                    return;
                }

                $valid = $extension === 'xlsx'
                    ? str_starts_with($head, "PK\x03\x04")
                    : ! str_contains($head, "\0");

                if (! $valid) {
                    $validator->errors()->add('file', (string) __('ventmail.audience.import.limit.format'));
                }
            },
            function (Validator $validator): void {
                if ($this->input('mode') !== 'paste' || $validator->errors()->has('emails')) {
                    return;
                }

                $lines = ImportPaste::entries((string) $this->input('emails'));

                if ($lines === []) {
                    $validator->errors()->add('emails', (string) __('ventmail.audience.import.pasteEmpty'));
                } elseif (count($lines) > (int) config('audience.import.manual_max_rows')) {
                    $validator->errors()->add('emails', (string) __('ventmail.audience.import.limit.rows'));
                }
            },
        ];
    }
}
