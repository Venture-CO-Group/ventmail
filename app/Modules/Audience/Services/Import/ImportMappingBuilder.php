<?php

declare(strict_types=1);

namespace App\Modules\Audience\Services\Import;

use App\Modules\Validation\Services\EmailSyntax;

/**
 * A KEZDETI oszlop-leképezés a fájl felméréséből: fejléc-javaslat (7 nyelven), és ha a fejléc
 * nem árulja el az e-mail oszlopot, a MINTA-sorok alapján keresett cím-oszlop.
 *
 * Egy cél csak egyszer szerepelhet: ha két oszlop ugyanazt javasolja (`Email` és `E-mail
 * cím`), az ELSŐ kapja, a második `skip` — a felhasználó a képernyőn átállíthatja.
 */
final class ImportMappingBuilder
{
    public function __construct(
        private readonly ColumnSuggester $suggester,
        private readonly EmailSyntax $syntax,
    ) {}

    /**
     * @param  array<string, mixed>  $profile
     * @param  list<array{key: string, label: string}>  $fields
     * @return array<int, string> oszlop-index → cél (`email`, `first_name`, `last_name`, `attr:<kulcs>`, `skip`)
     */
    public function initial(array $profile, array $fields = []): array
    {
        $width = (int) ($profile['width'] ?? 0);
        $headers = (array) ($profile['columns'] ?? []);

        $mapping = [];
        $used = [];

        for ($column = 0; $column < $width; $column++) {
            $target = $this->suggester->suggest((string) ($headers[$column] ?? ''), $fields)['target'];

            if ($target === null || isset($used[$target])) {
                $mapping[$column] = 'skip';

                continue;
            }

            $used[$target] = true;
            $mapping[$column] = $target;
        }

        if (! isset($used['email'])) {
            $guess = $this->emailColumnFromSample($profile);

            if ($guess !== null) {
                // Ha a fejléc-javaslat már elfoglalta ezt az oszlopot valami másra, az e-mail erősebb.
                $mapping[$guess] = 'email';
            }
        }

        return $mapping;
    }

    /**
     * Javaslat a felhasználónak a képernyőn: oszloponként fejléc, minta és javasolt cél.
     *
     * @param  array<string, mixed>  $profile
     * @param  list<array{key: string, label: string}>  $fields
     * @return list<array{index: int, header: string, sample: string, target: string|null, confidence: int}>
     */
    public function suggestions(array $profile, array $fields = []): array
    {
        $width = (int) ($profile['width'] ?? 0);
        $headers = (array) ($profile['columns'] ?? []);
        $sample = (array) ($profile['sample'] ?? []);

        $out = [];

        for ($column = 0; $column < $width; $column++) {
            $header = (string) ($headers[$column] ?? '');
            $suggestion = $this->suggester->suggest($header, $fields);

            $out[] = [
                'index' => $column,
                'header' => $header,
                'sample' => (string) ($sample[0][$column] ?? ''),
                'target' => $suggestion['target'],
                'confidence' => $suggestion['confidence'],
            ];
        }

        return $out;
    }

    /** @param array<string, mixed> $profile */
    private function emailColumnFromSample(array $profile): ?int
    {
        $sample = (array) ($profile['sample'] ?? []);
        $width = (int) ($profile['width'] ?? 0);

        if ($sample === []) {
            return null;
        }

        for ($column = 0; $column < $width; $column++) {
            $valid = 0;

            foreach ($sample as $row) {
                $cell = (string) ($row[$column] ?? '');

                if (str_contains($cell, '@') && $this->syntax->valid($cell)) {
                    $valid++;
                }
            }

            if ($valid * 2 > count($sample)) {
                return $column;
            }
        }

        return null;
    }
}
