<?php

declare(strict_types=1);

namespace App\Modules\Sending\Services;

use App\Modules\Sending\Enums\Channel;
use App\Modules\Sending\Gate\GateReason;
use App\Modules\Sending\Models\Message;

/**
 * Az éles küldési mód döntése (deploy-ovh, kemény szabály 3).
 *
 * KÉT helyen fut: a PreSendGate-ben (a hívó azonnal látja az elutasítást) és a
 * MessageDispatcherben a driver hívása ELŐTT. A második azért kell, mert a gate-döntés és a
 * küldés között idő telik (sor, ütemezés): ha a tulajdonos közben visszazárja a küldést, a
 * sorban álló levél sem mehet ki.
 */
final class SendingMode
{
    /** Null = engedélyezett; különben az elutasítás oka. */
    public function restriction(Message $message): ?GateReason
    {
        $channel = Channel::tryFrom((string) $message->channel);

        if ($channel === null) {
            return GateReason::InvalidRequest;
        }

        if ($channel->requiresConsent() && ! (bool) config('sending.marketing_enabled')) {
            return GateReason::ChannelDisabled;
        }

        if (! $this->allowlistApplies()) {
            return null;
        }

        foreach ($message->recipients as $recipient) {
            if (! $this->allowlisted((string) $recipient)) {
                return GateReason::SendingRestricted;
            }
        }

        return null;
    }

    /** Nem élesben az allowlist MINDIG érvényes — a kötelezőség kikapcsolása nem nyit ki. */
    private function allowlistApplies(): bool
    {
        return ! (bool) config('sending.live') || (bool) config('sending.allowlist_enforced', true);
    }

    private function allowlisted(string $recipient): bool
    {
        $address = strtolower(trim($recipient));
        $at = strrpos($address, '@');

        if ($at === false) {
            return false;
        }

        $domain = substr($address, $at);

        foreach ((array) config('sending.allowlist', []) as $entry) {
            $entry = strtolower(trim((string) $entry));

            if ($entry === '') {
                continue;
            }

            // Domain-bejegyzés: PONTOS egyezés a `@`-tól — aldomain és hasonló végződés nem.
            if (str_starts_with($entry, '@') ? $domain === $entry : $address === $entry) {
                return true;
            }
        }

        return false;
    }
}
