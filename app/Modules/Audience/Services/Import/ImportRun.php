<?php

declare(strict_types=1);

namespace App\Modules\Audience\Services\Import;

use App\Modules\Audience\Models\ContactImport;
use App\Modules\Audience\Models\ContactList;
use App\Modules\Audience\Models\ContactTag;
use Carbon\CarbonImmutable;

/**
 * Egy futás ÁLLAPOTA (számlálók, minták, leképezés-terv) — a feldolgozó szolgáltatás maga
 * állapotmentes marad, a futás állapota ebben az objektumban él.
 */
final class ImportRun
{
    public const MAX_SAMPLES = 30;

    public const SAMPLES_PER_CODE = 5;

    public int $processed = 0;

    public int $new = 0;

    public int $updated = 0;

    public int $skipped = 0;

    public int $errors = 0;

    /** @var array<string, int> */
    public array $buckets = ['invalid' => 0, 'duplicate' => 0, 'existing' => 0, 'suppressed' => 0, 'role' => 0, 'disposable' => 0];

    /** @var list<array{line: int, email: string, name: string, code: string, detail: array<string, mixed>}> */
    public array $samples = [];

    /** @var array<string, int> */
    public array $samplesPerCode = [];

    public int $chunks = 0;

    /** @var array<string, int> a fájlban már látott cím (hash első 12 bájtja) → első sora */
    public array $seen = [];

    public ?ImportErrorReport $report = null;

    public ?ContactList $list = null;

    /** @var list<ContactTag> */
    public array $tags = [];

    public float $startedAt;

    /**
     * @param  array<string, string>  $types  attribútum-kulcs → típus
     * @param  array<string, int>  $attributeColumns  attribútum-kulcs → oszlop-index
     */
    public function __construct(
        public readonly ContactImport $import,
        public readonly bool $commit,
        public readonly int $emailColumn,
        public readonly ?int $firstNameColumn,
        public readonly ?int $lastNameColumn,
        public readonly array $attributeColumns,
        public readonly array $types,
        public readonly string $status,
        public readonly string $legalBasis,
        public readonly string $evidence,
        public readonly string $updateMode,
        public readonly bool $skipRoles,
        public readonly bool $skipDisposable,
        public readonly ?CarbonImmutable $consentDate,
    ) {
        $this->startedAt = microtime(true);
    }
}
