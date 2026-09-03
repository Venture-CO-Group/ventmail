<?php

declare(strict_types=1);

namespace App\Modules\Sending\Data;

/**
 * A bounce OSZTÁLYA — ez üzleti döntés, nem szépítés (20. kritérium).
 *
 * A `hard` AZONNALI suppression (a cím nem létezik, minden további küldés a reputációt
 * rombolja), a `soft` viszont csak N próba után. Ha a kettő összekeveredik, vagy egy létező
 * postafiókot zárunk ki véglegesen (elveszett vevő), vagy egy nem létező címre küldünk
 * örökké (blocklist).
 */
enum BounceClass: string
{
    case Hard = 'hard';
    case Soft = 'soft';
    case MailboxFull = 'mailbox_full';
    case Blocked = 'blocked';
    case Spam = 'spam';
    case Policy = 'policy';

    /** Azonnal suppression-listára kerül-e a cím? */
    public function suppressesImmediately(): bool
    {
        return $this === self::Hard;
    }

    /**
     * A hiba RÓLUNK szól, nem a címzettről.
     *
     * A blocklist, a spam-minősítés és a policy-elutasítás a küldő oldalán van: a cím jó.
     * Ha ezeket hardként kezelnénk, a saját hibánk miatt veszítenénk el a címzettet — és
     * közben nem néznénk meg, ami tényleg baj: a reputációnkat.
     */
    public function indicatesSenderProblem(): bool
    {
        return in_array($this, [self::Blocked, self::Spam, self::Policy], true);
    }
}
