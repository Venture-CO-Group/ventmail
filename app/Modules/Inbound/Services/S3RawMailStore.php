<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Services;

use App\Modules\Inbound\Contracts\RawMailStore;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/** A produkciós tár: Wasabi EU, S3-kompatibilis diszken keresztül. */
final class S3RawMailStore implements RawMailStore
{
    public function put(string $key, string $contents): string
    {
        $this->disk()->put($key, $contents);

        return $key;
    }

    public function get(string $key): ?string
    {
        return $this->disk()->exists($key) ? (string) $this->disk()->get($key) : null;
    }

    public function delete(string $key): void
    {
        $this->disk()->delete($key);
    }

    public function temporaryUrl(string $key, int $seconds): string
    {
        return $this->disk()->temporaryUrl($key, now()->addSeconds($seconds));
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('inbound.disk', 'inbound'));
    }
}
