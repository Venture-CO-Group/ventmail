<?php

declare(strict_types=1);

namespace App\Modules\Audience\Services\Import;

use App\Modules\Audience\Models\ContactImport;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Az import-fájlok TÁROLÁSA — privát lemezen, workspace-könyvtárban, soha nem a publikuson.
 *
 * ## Életciklus
 *
 *  - feltöltéskor ide kerül (`imports/{workspace}/{uuid}.{ext}`);
 *  - a futás végén TÖRLŐDIK (a forrásfájl személyes adatok tömege, megőrzésére nincs ok);
 *  - a megszakított / elhagyott draftot a `audience:purge-imports` takarítja;
 *  - a HIBARIPORT (`{uuid}-report.csv`) a megőrzési idő végéig marad, hogy letölthető legyen.
 *
 * // TODO ventVault: a fájltár a ventVault feladata — amíg nem él, az ideiglenes feldolgozási
 * // fájl a privát helyi lemezen van, és a fenti életciklus szerint rövid életű.
 */
final class ImportUploads
{
    /**
     * A PHP tényleges feltöltési plafonja bájtban: `min(upload_max_filesize, post_max_size)`.
     * A felület a konfigurált import-plafon és EZ minimumát mutatja — különben a felhasználó
     * a 100 MB-os fájlnál a webszerver 413-át kapná, érthető üzenet nélkül.
     */
    public static function phpUploadCeiling(): int
    {
        $bytes = static function (string|false $value): int {
            $value = trim((string) $value);

            if ($value === '' || $value === '-1' || $value === '0') {
                return PHP_INT_MAX;
            }

            $number = (int) $value;

            return match (strtoupper(substr($value, -1))) {
                'G' => $number * 1024 ** 3,
                'M' => $number * 1024 ** 2,
                'K' => $number * 1024,
                default => $number,
            };
        };

        return min($bytes(ini_get('upload_max_filesize')), $bytes(ini_get('post_max_size')));
    }

    public function disk(): Filesystem
    {
        return Storage::disk((string) config('audience.import.disk'));
    }

    /** Feltöltött fájl mentése; a relatív útvonallal tér vissza. */
    public function storeUpload(UploadedFile $file, int $workspaceId, string $extension): string
    {
        $path = $this->newPath($workspaceId, $extension);

        $stream = fopen($file->getRealPath(), 'rb');

        if ($stream === false || $this->disk()->put($path, $stream) === false) {
            throw new RuntimeException('Az import-fájl nem menthető.');
        }

        if (is_resource($stream)) {
            fclose($stream);
        }

        return $path;
    }

    /** Szövegből (kézi / beillesztett bevitel) generált CSV mentése. */
    public function storeText(string $contents, int $workspaceId): string
    {
        $path = $this->newPath($workspaceId, 'csv');

        if ($this->disk()->put($path, $contents) === false) {
            throw new RuntimeException('Az import-fájl nem menthető.');
        }

        return $path;
    }

    public function absolute(string $relative): string
    {
        return $this->disk()->path($relative);
    }

    public function exists(?string $relative): bool
    {
        return $relative !== null && $relative !== '' && $this->disk()->exists($relative);
    }

    public function delete(?string $relative): void
    {
        if ($relative !== null && $relative !== '') {
            $this->disk()->delete($relative);
        }
    }

    public function reportPath(ContactImport $import): string
    {
        return 'imports/'.$import->workspace_id.'/'.$import->uuid.'-report.csv';
    }

    /**
     * Az openspout ELŐTÖLTŐ (sharedStrings-gyorsítótár) fájljainak KÖTEG-szintű ideiglenes
     * könyvtára — nem a rendszer `/tmp`-je: ott egy megszakadt (OOM) felmérés maradéka futásonként
     * ~1 GB-ot hagyna. Törlése a `finally`-ben, a `failed()`-ben és a purge-ban (1 óránál régebbi).
     */
    public function tempDir(string $importUuid): string
    {
        $relative = 'import-tmp/'.$importUuid;
        $this->disk()->makeDirectory($relative);

        return $this->disk()->path($relative);
    }

    public function cleanTemp(string $importUuid): void
    {
        $this->disk()->deleteDirectory('import-tmp/'.$importUuid);
    }

    /** A feldolgozás olvasója a köteg formátuma szerint. */
    public function reader(ContactImport $import): ImportRowReader
    {
        $profile = (array) $import->profile;
        $path = $this->absolute((string) $import->storage_path);

        return ($profile['format'] ?? 'csv') === 'xlsx'
            // IZOLÁLT gyerekfolyamat (D220): az import sorolvasása sem a worker folyamatában fut.
            ? new IsolatedXlsxRowReader($path, $profile['sheet'] ?? null, $this->tempDir((string) $import->uuid), (int) config('audience.import.xlsx_child_timeout_import'))
            : new CsvRowReader($path, (string) ($profile['delimiter'] ?? ','), (string) ($profile['encoding'] ?? 'UTF-8'));
    }

    private function newPath(int $workspaceId, string $extension): string
    {
        return 'imports/'.$workspaceId.'/'.Str::uuid()->toString().'.'.$extension;
    }
}
