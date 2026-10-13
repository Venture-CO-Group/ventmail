<?php

declare(strict_types=1);

namespace App\Modules\Journey\Services;

use App\Modules\Audit\Services\KeyedHash;
use App\Modules\Journey\Models\JourneyRun;
use App\Modules\Sending\Services\DomainNormalizer;

/**
 * ESEMÉNY-VEZÉRELT ÉBRESZTÉS az eseményre váró `wait` csomóponthoz (review REV-S3).
 *
 * A nyitás / kattintás rögzítésekor a címzett VÁRAKOZÓ futásait (a `_wait` jelölővel) azonnal
 * esedékessé teszi: a következő tick ellenőrzi az eseményt és továbblép. Így a motor nem
 * szkenneli percenként a `message_events` táblát minden várakozó futásra.
 *
 * Csak `resume_at`-ot ír (idempotens, nem lép), a workspace global scope-on belül, a cím
 * kulcsolt lenyomatán (`journey_runs (workspace_id, email_hash)` index).
 */
final class JourneyWaitWaker
{
    public function wake(string $recipient): int
    {
        $email = DomainNormalizer::listKey(trim($recipient));

        if ($email === '') {
            return 0;
        }

        return JourneyRun::query()
            ->where('email_hash', KeyedHash::of('email', $email))
            ->where('status', 'waiting')
            // CSAK az ESEMÉNYRE váró futás (review 2, N1): az időalapú várakozást nem rövidítjük.
            ->whereRaw("context->'_wait'->>'event' = 'true'")
            ->where('resume_at', '>', now())
            ->update(['resume_at' => now()]);
    }
}
