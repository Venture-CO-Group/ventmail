<?php

declare(strict_types=1);

namespace App\Modules\Smtp\Data;

use Carbon\CarbonInterface;

/** A vezérlő fejlécekből kiolvasott küldési beállítások. */
final class SubmissionControls
{
    /** @var array<string, string> a levélben MARADÓ fejlécek */
    public array $headers = [];

    /** @var list<string> */
    public array $tags = [];

    /** @var array<string, mixed> */
    public array $variables = [];

    public ?CarbonInterface $deliverytime = null;

    public bool $testmode = false;

    /** Az ELSŐ hiba: a vezérlés hibája elutasítás, nem csendes eldobás. */
    public ?string $error = null;
}
