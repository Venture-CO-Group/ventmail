<?php

declare(strict_types=1);

namespace App\Modules\Identity\Enums;

/**
 * A hat ventMail-szerep. Forrás: a ventSSO token `resource_access.ventmail.roles` claimje
 * (spec 3.3). Ismeretlen szerep NEM ad jogot — a leképezés fehérlistás.
 */
enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Developer = 'developer';
    case Editor = 'editor';
    case Author = 'author';
    case Analyst = 'analyst';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $role) => $role->value, self::cases());
    }

    public static function tryFromClaim(string $claim): ?self
    {
        return self::tryFrom(strtolower(trim($claim)));
    }
}
