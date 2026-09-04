<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Testing;

use App\Modules\Inbound\Contracts\RawMailStore;

/** Memóriában élő tár a tesztekhez — a kontraktus determinisztikus párja. */
final class FakeRawMailStore implements RawMailStore
{
    /** @var array<string, string> */
    public array $files = [];

    public function put(string $key, string $contents): string
    {
        $this->files[$key] = $contents;

        return $key;
    }

    public function get(string $key): ?string
    {
        return $this->files[$key] ?? null;
    }

    public function delete(string $key): void
    {
        unset($this->files[$key]);
    }

    public function temporaryUrl(string $key, int $seconds): string
    {
        return 'https://fake-store.test/'.$key.'?expires='.(time() + $seconds);
    }
}
