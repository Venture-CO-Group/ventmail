<?php

declare(strict_types=1);

namespace App\Modules\Journey\Services;

use App\Modules\Audience\Models\Contact;
use App\Modules\Journey\Graph\JourneyGraph;
use App\Modules\Journey\Graph\NodeCatalog;
use App\Modules\Sending\Gate\Gates\ConsentGate;
use App\Modules\Sending\Gate\Gates\DomainVerifiedGate;
use App\Modules\Sending\Gate\Gates\SendingModeGate;
use App\Modules\Sending\Gate\Gates\SuppressionGate;
use App\Modules\Sending\Models\Message;
use App\Modules\Sending\Services\DomainNormalizer;
use App\Modules\Template\Models\Template;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * TESZT-FUTTATÁS (v1.1 · 6. modul): egy kiválasztott kontakt útja a gráfon, GYORSÍTOTT
 * időben, VALÓDI KÜLDÉS NÉLKÜL.
 *
 * ## Csak olvas — és ez a lényeg
 *
 * A szimulátor NEM hozza létre a futást, NEM hívja a `SendMessage`-et, a drivert és a
 * `PreSendGate::evaluate()`-et sem (az auditál, és a rate-limit kapuja tokent fogyaszt). A
 * „kimenne-e a levél?" kérdésre a MELLÉKHATÁS NÉLKÜLI kapukat kérdezi meg egy MENTETLEN
 * `Message` példányon: küldési mód (SENDING_LIVE / marketing-kapcsoló / seed-allowlist),
 * hitelesített feladó-domain, suppression, consent. A rate-limit, az entitlement-mérés és a
 * tartalom-kapuk kimaradnak — a válasz ezért „kimenne" / „a kapu megállítaná (ok)", nem
 * garancia.
 *
 * A feltételek a MOTOR kódján (`JourneyConditions`) értékelődnek ki, az A/B sorsolás a motor
 * `pickVariant()`-jával: a teszt ugyanazt az ágat mutatja, amit egy valódi futás választana
 * (az A/B-nél a kontakt címe a mag, nem a még nem létező futás azonosítója).
 *
 * Az idő GYORSÍTOTT: a várakozás nem vár, csak a szimulált órát lépteti előre.
 */
final class JourneySimulator
{
    public const MAX_STEPS = 100;

    /**
     * A kiértékelt FELTÉTELEK plafonja futásonként (review SEC-S1 / SEC-S3): a teszt-futtatás
     * nem lehet tetszőleges számú lekérdezés orákuluma. A szegmens-feltétel külön, szűkebb
     * plafont kap (a szegmens-lekérdezés a legdrágább).
     */
    public const MAX_CONDITIONS = 25;

    public const MAX_SEGMENT_CONDITIONS = 5;

    private int $conditionsEvaluated = 0;

    private int $segmentsEvaluated = 0;

    public function __construct(private readonly JourneyConditions $conditions) {}

    /**
     * @return array{contact: string, found: bool, steps: list<array<string, mixed>>, sends: int, realSends: int, simulatedMinutes: int, stopped: ?string}
     */
    /**
     * @param  bool  $detailed  a kapu-okok és a feltétel-kimenetek láthatók-e (review SEC-S1:
     *                          `audience.manage` nélkül csak az út — a csomópontok sorrendje)
     */
    public function run(JourneyGraph $graph, string $email, bool $detailed = true): array
    {
        $this->conditionsEvaluated = 0;
        $this->segmentsEvaluated = 0;

        $email = DomainNormalizer::listKey(trim($email));
        $contact = Contact::query()->where('email', $email)->first();
        $clock = 0;
        $steps = [];
        $sends = 0;

        // REFERENCIÁVAL: az eredmény a lezáráskori állapotot adja, nem a definíciókorit.
        $result = function (?string $stopped) use ($email, $contact, $detailed, &$steps, &$sends, &$clock): array {
            return [
                'contact' => $email,
                'found' => $contact !== null,
                'redacted' => ! $detailed,
                'steps' => $detailed ? $steps : array_map(self::redact(...), $steps),
                'sends' => $detailed ? $sends : 0,
                // A VALÓDI küldések száma — mindig nulla; a felület ezt írja ki.
                'realSends' => 0,
                'simulatedMinutes' => $clock,
                'stopped' => $stopped,
            ];
        };

        $trigger = $graph->triggers()[0] ?? null;

        if ($contact === null || $trigger === null) {
            return $result($contact === null ? 'contact_missing' : 'missing_trigger');
        }

        $steps[] = $this->entry($trigger['id'], $trigger['type'], 'entered', null, $clock);
        $current = $graph->target($trigger['id'], 'out');

        while ($current !== null) {
            if (count($steps) > self::MAX_STEPS) {
                return $result('step_limit');
            }

            $node = $graph->node($current);

            if ($node === null || ! NodeCatalog::isAction($node['type'])) {
                return $result('invalid_node');
            }

            try {
                [$outcome, $handle, $detail, $minutes] = $this->simulate($node, $email, $contact);
            } catch (SimulationLimitReached) {
                return $result('condition_limit');
            }

            $clock += $minutes;

            if ($outcome === 'would_send') {
                $sends++;
            }

            $steps[] = $this->entry($node['id'], $node['type'], $outcome, $detail, $clock, $handle);

            if ($handle === null) {
                return $result(null);
            }

            $current = $graph->target($node['id'], $handle);
        }

        $steps[] = $this->entry(null, null, 'end', null, $clock);

        return $result(null);
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array{0: string, 1: ?string, 2: ?string, 3: int} [kimenet, ág, részlet, eltelt perc]
     */
    private function simulate(array $node, string $email, Contact $contact): array
    {
        $config = $node['config'];

        return match ($node['type']) {
            'send_email' => [...$this->send($config, $email), 0],
            'wait' => $this->wait($config, $email),
            'condition' => $this->evaluate($config, $email, $contact)
                ? ['condition_true', 'yes', null, 0]
                : ['condition_false', 'no', null, 0],
            'split' => $this->split($config, $email, $contact),
            'ab_split' => $this->abSplit($config, $email, $node['id']),
            'update_contact', 'list_membership' => ['would_update', 'out', (string) ($config['operation'] ?? ''), 0],
            'webhook' => ['skipped_test_mode', 'out', null, 0],
            'goal' => ['goal', null, null, 0],
            default => ['exit', null, null, 0],
        };
    }

    /**
     * KIMENNE-E a levél? — mellékhatás nélküli kapuk egy MENTETLEN üzeneten.
     *
     * @param  array<string, mixed>  $config
     * @return array{0: string, 1: string, 2: ?string} [kimenet, ág, ok]
     */
    private function send(array $config, string $email): array
    {
        $uuid = (string) ($config['template'] ?? '');
        $template = Str::isUuid($uuid) ? Template::query()->where('uuid', $uuid)->first() : null;

        if ($template === null) {
            return ['blocked', 'out', 'template_missing'];
        }

        $message = new Message([
            'channel' => 'journey',
            'from_email' => (string) ($config['from_email'] ?? config('mail.from.address')),
            'recipients' => [$email],
            'options' => ['studio_origin' => 'journey'],
        ]);

        foreach ([SendingModeGate::class, DomainVerifiedGate::class, SuppressionGate::class, ConsentGate::class] as $gate) {
            $decision = app($gate)->check($message);

            if (! $decision->passed) {
                return ['blocked', 'out', (string) $decision->reason];
            }
        }

        return ['would_send', 'out', null];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{0: string, 1: ?string, 2: ?string, 3: int}
     */
    private function abSplit(array $config, string $email, string $node): array
    {
        $handle = JourneyEngine::pickVariant((array) ($config['variants'] ?? []), 'test|'.$email.'|'.$node);

        return ['ab_variant', $handle, $handle, 0];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{0: string, 1: ?string, 2: ?string, 3: int}
     */
    private function wait(array $config, string $email): array
    {
        if (($config['mode'] ?? 'time') !== 'event') {
            $minutes = max(1, (int) ($config['minutes'] ?? 60));

            return ['waited', 'out', (string) $minutes, $minutes];
        }

        $timeout = max(1, (int) ($config['timeout_minutes'] ?? 60));

        // Az esemény a JÖVŐBEN nem látható: a teszt azt nézi, megtörtént-e már az ablakon belül.
        return $this->conditions->hadEvent($email, (string) ($config['event'] ?? ''), now()->subMinutes($timeout))
            ? ['event_happened', 'out', (string) ($config['event'] ?? ''), 0]
            : ['event_timeout', 'timeout', (string) ($config['event'] ?? ''), $timeout];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{0: string, 1: ?string, 2: ?string, 3: int}
     */
    private function split(array $config, string $email, Contact $contact): array
    {
        foreach ((array) ($config['branches'] ?? []) as $branch) {
            if (is_array($branch) && $this->evaluate((array) ($branch['condition'] ?? []), $email, $contact)) {
                return ['split_branch', (string) ($branch['key'] ?? 'else'), (string) ($branch['key'] ?? ''), 0];
            }
        }

        return ['split_branch', 'else', 'else', 0];
    }

    /**
     * Feltétel-kiértékelés PLAFONNAL.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws SimulationLimitReached
     */
    private function evaluate(array $config, string $email, Contact $contact): bool
    {
        if (++$this->conditionsEvaluated > self::MAX_CONDITIONS) {
            throw new SimulationLimitReached;
        }

        if (($config['type'] ?? null) === 'in_segment' && ++$this->segmentsEvaluated > self::MAX_SEGMENT_CONDITIONS) {
            throw new SimulationLimitReached;
        }

        return $this->conditions->evaluate($config, $email, $contact);
    }

    /**
     * CSAK AZ ÚT (review SEC-S1): a csomópont és az idő marad, a kapu-ok, a feltétel-kimenet és
     * az ág neve nem — a teszt-futtatás ne legyen a kontakt-adatok (suppression, consent,
     * nyitás, címke, mezőérték) orákuluma annak, aki a közönséget amúgy nem láthatja.
     *
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private static function redact(array $step): array
    {
        $keep = ['entered', 'goal', 'exit', 'end', 'waited'];

        return [
            ...$step,
            'outcome' => in_array($step['outcome'], $keep, true) ? $step['outcome'] : 'visited',
            'detail' => null,
            'handle' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function entry(?string $node, ?string $type, string $outcome, ?string $detail, int $minutes, ?string $handle = null): array
    {
        return [
            'node' => $node,
            'type' => $type,
            'outcome' => $outcome,
            'detail' => $detail,
            'handle' => $handle,
            'at' => CarbonImmutable::now()->addMinutes($minutes)->toIso8601String(),
            'minutes' => $minutes,
        ];
    }
}
