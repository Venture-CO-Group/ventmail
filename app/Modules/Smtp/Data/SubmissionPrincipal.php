<?php

declare(strict_types=1);

namespace App\Modules\Smtp\Data;

/**
 * A HITELESÍTETT session bizonyítéka.
 *
 * Ez a típus maga a bizalmi határ: a `SubmissionSink::accept()` első paramétere
 * nem-nullable `SubmissionPrincipal`, tehát hitelesítetlen sessionből a hívás nem is
 * MEGFOGALMAZHATÓ — nincs „ha null, akkor default workspace" ág, mert nincs hova írni.
 * A nyílt relay tilalma így nem egy `if`-en múlik (golden rule 3).
 */
final readonly class SubmissionPrincipal
{
    public function __construct(
        public int $workspaceId,
        public string $credentialUuid,
        public ?int $domainId = null,
        public string $channel = 'transactional',
        public bool $testmode = false,
    ) {}
}
