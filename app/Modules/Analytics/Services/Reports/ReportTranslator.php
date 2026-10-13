<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Services\Reports;

/**
 * A RIPORT szövegei a felület fordításaiból (`resources/js/locales/<locale>.json`) — EGY
 * fordítási forrás a képernyőnek és a PDF-nek (D243): a riport ugyanazokat a feliratokat
 * mutatja, mint a grafikon-kártya, és egy új nyelv egy helyen bővül. Hiányzó kulcs → a magyar
 * referencia, végül maga a kulcs (soha nem üres felirat).
 */
final class ReportTranslator
{
    /** @var array<string, array<string, mixed>> */
    private static array $loaded = [];

    public function __construct(public readonly string $locale) {}

    /** @param array<string, string|int|float> $params */
    public function get(string $key, array $params = []): string
    {
        $value = self::lookup($this->locale, $key) ?? self::lookup('hu', $key) ?? $key;

        foreach ($params as $name => $replacement) {
            $value = str_replace('{'.$name.'}', (string) $replacement, $value);
        }

        return $value;
    }

    private static function lookup(string $locale, string $key): ?string
    {
        if (preg_match('/^[a-z]{2}$/', $locale) !== 1) {
            return null;
        }

        if (! isset(self::$loaded[$locale])) {
            $path = resource_path('js/locales/'.$locale.'.json');
            self::$loaded[$locale] = is_file($path) ? (array) json_decode((string) file_get_contents($path), true) : [];
        }

        $node = self::$loaded[$locale];

        foreach (explode('.', $key) as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return null;
            }

            $node = $node[$segment];
        }

        return is_string($node) ? $node : null;
    }
}
