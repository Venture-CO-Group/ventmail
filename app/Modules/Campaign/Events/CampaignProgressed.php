<?php

declare(strict_types=1);

namespace App\Modules\Campaign\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A KAMPÁNY HALADÁSA — Reverb-en a felületre (65. kritérium).
 *
 * ## A CSATORNA PRIVÁT, workspace-enként
 *
 * Egy közös csatorna azt jelentené, hogy minden tenant látja minden más tenant kampányának
 * a haladását — a kampány NEVÉVEL és a címzettszámmal együtt. Ez üzleti információ: hány
 * ügyfele van a versenytársnak, mikor küld. A csatorna ezért a workspace id-jét hordozza, és
 * a jogosultságot a `channels.php` őrzi.
 *
 * ## A HASZNOS SZÁM, nem a nyers
 *
 * Az esemény DARABSZÁMOKAT hordoz, nem címzett-listát. A felületnek egy progresszsáv kell;
 * a címek WebSocketen való szórása a legérzékenyebb adatot tenné a legkevésbé kontrollált
 * csatornára.
 */
final class CampaignProgressed implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(
        public readonly string $campaignUuid,
        public readonly int $workspaceId,
        public readonly int $total,
        public readonly int $sent,
        public readonly int $failed,
    ) {}

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('workspace.'.$this->workspaceId.'.campaigns')];
    }

    public function broadcastAs(): string
    {
        return 'campaign.progressed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'campaign' => $this->campaignUuid,
            'total' => $this->total,
            'sent' => $this->sent,
            'failed' => $this->failed,
            'percent' => $this->total === 0 ? 0 : (int) round(($this->sent + $this->failed) / $this->total * 100),
        ];
    }
}
