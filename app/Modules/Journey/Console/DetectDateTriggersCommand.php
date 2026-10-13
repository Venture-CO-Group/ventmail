<?php

declare(strict_types=1);

namespace App\Modules\Journey\Console;

use App\Modules\Audience\Models\Contact;
use App\Modules\Journey\Enums\TriggerType;
use App\Modules\Journey\Models\Journey;
use App\Modules\Journey\Services\JourneyTrigger;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use App\Support\WorkspaceTimezone;
use Illuminate\Console\Command;
use Throwable;

/**
 * A DÁTUM / ÉVFORDULÓ trigger (v1.1 · 6. modul).
 *
 * Ugyanaz a gondolat, mint a szegmensbe lépésnél: a dátum nem esemény, hanem ÁLLAPOT — nincs
 * pillanat, amikor „bekövetkezik". Naponta egyszer megnézzük, kinek a dátum-mezője (hónap-nap)
 * esik MÁRA (az eltolással: `offset_days = -1` → egy nappal az évforduló előtt).
 *
 * A dátum az egyéni mezőben `ÉÉÉÉ-HH-NN` alakú; más alakú érték nem illeszkedik (nem
 * találgatunk: egy rosszul értelmezett dátum rossz napon küld levelet).
 */
final class DetectDateTriggersCommand extends Command
{
    protected $signature = 'journeys:detect-dates';

    protected $description = 'A dátum / évforduló triggerű journey-k napi beléptetése.';

    public function handle(WorkspaceContext $context, JourneyTrigger $trigger): int
    {
        $entered = 0;
        $previous = $context->hasWorkspace() ? $context->workspace() : null;

        try {
            Workspace::query()->orderBy('id')->chunkById(50, function ($workspaces) use ($context, $trigger, &$entered): void {
                foreach ($workspaces as $workspace) {
                    $context->set($workspace);

                    $journeys = Journey::query()
                        ->where('status', 'active')
                        ->where('trigger_type', TriggerType::DateAnniversary->value)
                        ->get();

                    foreach ($journeys as $journey) {
                        try {
                            $entered += $this->enterJourney($journey, $trigger);
                        } catch (Throwable $e) {
                            $this->warn(sprintf('A(z) %s journey dátum-vizsgálata elhasalt: %s', (string) $journey->uuid, $e::class));
                        }
                    }
                }
            });
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }

        $this->info("Új belépés: {$entered}");

        return self::SUCCESS;
    }

    private function enterJourney(Journey $journey, JourneyTrigger $trigger): int
    {
        $config = (array) $journey->trigger_config;
        $field = (string) ($config['field'] ?? '');

        // A mező-kulcs SQL-be paraméterként megy; a formátumát is szűkítjük.
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $field) !== 1) {
            return 0;
        }

        /*
         | A CÉLNAP a WORKSPACE IDŐZÓNÁJÁBAN (review NIT): egy budapesti tenantnak éjfél után már
         | „ma" van, amikor UTC-ben még tegnap.
         */
        $today = now(WorkspaceTimezone::for($journey->workspace))->startOfDay();
        $target = $today->copy()->subDays((int) ($config['offset_days'] ?? 0));
        $entered = 0;

        $query = Contact::query()
            ->where('status', 'subscribed')
            ->whereRaw("(attributes->>?) ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}'", [$field]);

        if (($config['mode'] ?? 'anniversary') === 'once') {
            // EGYSZERI dátum (review REV-S2): a teljes dátum egyezik.
            $query->whereRaw('substring(attributes->>? from 1 for 10) = ?', [$field, $target->format('Y-m-d')]);
        } else {
            // ÉVFORDULÓ: hónap-nap. A február 29-i dátum nem szökőévben február 28-án jön.
            $days = [$target->format('m-d')];

            if ($target->format('m-d') === '02-28' && ! $target->isLeapYear()) {
                $days[] = '02-29';
            }

            $query->whereRaw(
                'substring(attributes->>? from 6 for 5) in ('.implode(', ', array_fill(0, count($days), '?')).')',
                [$field, ...$days],
            );
        }

        $query
            ->orderBy('id')
            ->chunkById(500, function ($contacts) use ($journey, $trigger, $field, $today, &$entered): void {
                foreach ($contacts as $contact) {
                    if ($trigger->enter($journey, (string) $contact->email, ['field' => $field, 'date' => $today->toDateString()]) !== null) {
                        $entered++;
                    }
                }
            });

        return $entered;
    }
}
