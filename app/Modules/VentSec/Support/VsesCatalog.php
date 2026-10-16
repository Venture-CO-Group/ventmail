<?php

declare(strict_types=1);

namespace App\Modules\VentSec\Support;

/**
 * A ventSec VSES-katalógusának (`resources/vses/catalog.yaml`, `apps.ventmail` + a közös
 * `ventsec.heartbeat`) azon része, amit a ventMail kibocsát. A kontextus-kulcsok ZÁRT
 * allowlistje: ami nincs itt, az nem hagyhatja el a ventMailt (cím, fájlnév, tartalom, IP).
 * A drift-őr (`VentSecEventsTest`) a ventSec katalógus-másolatához méri.
 */
final class VsesCatalog
{
    public const OUTCOMES = ['success', 'failure', 'denied', 'unknown'];

    /** @var array<string, array{category: string, severity_hint: string, context: list<string>}> */
    private const ACTIONS = [
        'inbound.attachment_verdict' => ['category' => 'file', 'severity_hint' => 'info', 'context' => ['binary_verdict', 'scan_id']],
        'smtp.auth_failed' => ['category' => 'authentication', 'severity_hint' => 'low', 'context' => ['listener', 'reason']],
        'delivery.bounce_wave' => ['category' => 'api', 'severity_hint' => 'medium', 'context' => ['domain_hash', 'rate']],
        'ventsec.heartbeat' => ['category' => 'ventsec', 'severity_hint' => 'info', 'context' => ['outbox_depth', 'outbox_oldest_seconds', 'sdk_version']],
    ];

    /** @return array{category: string, severity_hint: string, context: list<string>}|null */
    public static function entry(string $action): ?array
    {
        return self::ACTIONS[$action] ?? null;
    }
}
