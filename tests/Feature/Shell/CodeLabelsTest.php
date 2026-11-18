<?php

declare(strict_types=1);

use App\Modules\Journey\Enums\TriggerType;
use App\Modules\Sending\Data\BounceClass;
use App\Modules\Sending\Enums\Channel;
use App\Modules\Sending\Gate\GateReason;
use App\Modules\Sending\Gate\Gates\Gate;
use App\Modules\Suppression\Enums\SuppressionReason;
use App\Modules\Suppression\Enums\SuppressionScope;
use Illuminate\Support\Facades\File;

/*
 | D292 — A GÉPI KÓDOK nem felületi szövegek. Minden enum-érték, ami a felületen megjelenik (kapu,
 | elutasítási ok, bounce-osztály, csatorna, tiltólista-ok és -hatókör, journey-trigger, DKIM-hiba),
 | MIND A HÉT nyelven fordított címkét kap (`codes.*`, ill. a meglévő `journeyCanvas.nodes`). Egy új
 | enum-eset fordítás nélkül itt bukik el — nem a felhasználó előtt, nyers kódként.
 */

function codeLocales(): array
{
    return collect(File::files(resource_path('js/locales')))
        ->mapWithKeys(fn ($file) => [$file->getFilenameWithoutExtension() => json_decode((string) file_get_contents($file->getPathname()), true, 512, JSON_THROW_ON_ERROR)])
        ->all();
}

it('minden felületen megjelenő gépi kódnak van címkéje mind a hét nyelven', function () {
    $locales = codeLocales();
    expect(array_keys($locales))->toHaveCount(7);

    $gates = collect(app()->tagged('sending.gates'))->map(fn (Gate $gate) => $gate->name())->values()->all();

    if ($gates === []) {
        $gates = collect(File::files(app_path('Modules/Sending/Gate/Gates')))
            ->map(fn ($file) => 'App\\Modules\\Sending\\Gate\\Gates\\'.$file->getFilenameWithoutExtension())
            ->filter(fn (string $class) => class_exists($class) && is_subclass_of($class, Gate::class) && ! (new ReflectionClass($class))->isAbstract())
            ->map(fn (string $class) => app($class)->name())
            ->values()->all();
    }

    $expected = [
        'codes.gate' => $gates,
        'codes.reason' => array_map(fn (GateReason $case) => $case->value, GateReason::cases()),
        'codes.bounce' => array_map(fn (BounceClass $case) => $case->value, BounceClass::cases()),
        'codes.channel' => array_map(fn (Channel $case) => $case->value, Channel::cases()),
        'codes.suppressionReason' => array_map(fn (SuppressionReason $case) => $case->value, SuppressionReason::cases()),
        'codes.scope' => array_map(fn (SuppressionScope $case) => $case->value, SuppressionScope::cases()),
        'codes.dkim' => ['no_signing_key', 'signing_failed'],
        'codes.event' => ['queued', 'accepted', 'rejected', 'delivered', 'deferred', 'bounced', 'complained', 'unsubscribed', 'opened', 'clicked', 'stored', 'failed'],
        'journeyCanvas.nodes' => array_map(fn (TriggerType $case) => $case->value, TriggerType::cases()),
    ];

    expect($gates)->not->toBeEmpty();

    $missing = [];

    foreach ($locales as $locale => $messages) {
        foreach ($expected as $group => $codes) {
            foreach ($codes as $code) {
                $label = data_get($messages, $group.'.'.$code);

                if (! is_string($label) || trim($label) === '') {
                    $missing[] = "{$locale}: {$group}.{$code}";
                }
            }
        }
    }

    expect($missing)->toBe([]);
});
