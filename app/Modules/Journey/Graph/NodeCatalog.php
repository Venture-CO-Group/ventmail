<?php

declare(strict_types=1);

namespace App\Modules\Journey\Graph;

use App\Modules\Journey\Enums\StepType;
use App\Modules\Journey\Enums\TriggerType;

/**
 * A VÁSZON CSOMÓPONT-KÉSZLETE (v1.1 · 6. modul) — egyetlen forrás a validátornak, a
 * publikálónak, a teszt-futtatónak és a felületnek.
 *
 * A trigger-csomópont `type`-ja a `TriggerType` értéke, az akcióé a `StepType`-é: a gráf és a
 * motor UGYANAZT a szót használja, nincs fordítótábla, ami elcsúszhatna.
 */
final class NodeCatalog
{
    /** A feltétel-típusok (condition, split ág, cél). */
    public const CONDITION_TYPES = ['in_segment', 'has_tag', 'opened_email', 'clicked_email', 'context_equals', 'field_equals'];

    public const GOAL_TYPES = ['in_segment', 'has_tag', 'clicked_email'];

    public const WAIT_EVENTS = ['opened', 'clicked'];

    public const MAX_SPLIT_BRANCHES = 8;

    public const MAX_AB_VARIANTS = 5;

    /** A trigger-csomópontok sorrendje a palettán. */
    public const TRIGGERS = [
        'list_subscribe', 'tag_added', 'tag_removed', 'field_changed', 'date_anniversary',
        'form_signup', 'api_event', 'campaign_interaction', 'segment_entered', 'inbound_email',
    ];

    /** Az akció-csomópontok sorrendje a palettán. */
    public const ACTIONS = [
        'send_email', 'wait', 'condition', 'split', 'ab_split',
        'update_contact', 'list_membership', 'webhook', 'goal', 'exit',
    ];

    /**
     * A még BEKÖTETLEN triggerek (review REV-S1): a forrásuknak ma nincs egykontaktos hívója
     * (a címkézés tömeges `TagOperations`, a mezőváltozás importból / API-ból jön). A
     * palettán „hamarosan", a validátor HIBÁT ad rájuk — egy némán sosem tüzelő journey nem
     * élesíthető. A bekötés döntése HUMAN-QUEUE.
     */
    public const UNAVAILABLE_TRIGGERS = ['tag_added', 'tag_removed', 'field_changed'];

    public const DATE_MODES = ['anniversary', 'once'];

    public static function isTrigger(string $type): bool
    {
        return TriggerType::tryFrom($type) !== null;
    }

    public static function isAction(string $type): bool
    {
        // A régi `add_tag` / `remove_tag` lépés a motorban él, de a vászon az `update_contact`
        // csomópontot kínálja helyette — a palettán nem szerepel.
        return in_array($type, self::ACTIONS, true);
    }

    public static function isKnown(string $type): bool
    {
        return self::isTrigger($type) || self::isAction($type);
    }

    /** Végpont-csomópont-e (nincs kimenete). */
    public static function isTerminal(string $type): bool
    {
        return in_array($type, ['goal', 'exit'], true);
    }

    /**
     * A csomópont KIMENETEI a konfigurációja szerint.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    public static function outputs(string $type, array $config): array
    {
        if (self::isTrigger($type)) {
            return ['out'];
        }

        return match ($type) {
            'condition' => ['yes', 'no'],
            'wait' => ($config['mode'] ?? 'time') === 'event' ? ['out', 'timeout'] : ['out'],
            'split' => [...array_values(array_map(
                static fn ($branch): string => (string) ($branch['key'] ?? ''),
                array_values(array_filter((array) ($config['branches'] ?? []), 'is_array')),
            )), 'else'],
            'ab_split' => array_values(array_map(
                static fn ($variant): string => (string) ($variant['key'] ?? ''),
                array_values(array_filter((array) ($config['variants'] ?? []), 'is_array')),
            )),
            'goal', 'exit' => [],
            default => ['out'],
        };
    }

    /** A motor lépés-típusa. */
    public static function stepType(string $type): StepType
    {
        return StepType::from($type);
    }

    /**
     * A felületnek: kategória + kimenetek alapértéke.
     *
     * @return array<string, list<string>>
     */
    public static function forUi(): array
    {
        return [
            'triggers' => self::TRIGGERS,
            'actions' => self::ACTIONS,
            'conditionTypes' => self::CONDITION_TYPES,
            'goalTypes' => self::GOAL_TYPES,
            'waitEvents' => self::WAIT_EVENTS,
            'unavailableTriggers' => self::UNAVAILABLE_TRIGGERS,
            'dateModes' => self::DATE_MODES,
        ];
    }
}
