<?php

declare(strict_types=1);

namespace App\Modules\Validation\Testing;

use App\Modules\Validation\Contracts\MailboxProbe;
use App\Modules\Validation\Data\ProbeResult;

/**
 * Determinisztikus szonda a tesztekhez (40. kritérium).
 *
 * SZÁMLÁL is: a rate-limit és a cache CSAK akkor bizonyítható, ha meg tudjuk mondani, hány
 * VALÓDI szonda ment ki. Enélkül a „cache-el" állítás mérhetetlen lenne.
 */
final class FakeMailboxProbe implements MailboxProbe
{
    /** @var array<string, ProbeResult> */
    private array $answers = [];

    /** @var list<string> a VALÓDI szondák, sorrendben */
    public array $probed = [];

    public ProbeResult $default;

    public function __construct()
    {
        $this->default = ProbeResult::accepted();
    }

    public function answer(string $email, ProbeResult $result): self
    {
        $this->answers[strtolower($email)] = $result;

        return $this;
    }

    public function probe(string $email): ProbeResult
    {
        $this->probed[] = $email;

        return $this->answers[strtolower($email)] ?? $this->default;
    }

    public function count(): int
    {
        return count($this->probed);
    }
}
