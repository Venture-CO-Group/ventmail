<?php

declare(strict_types=1);

namespace App\Modules\Audience\Services\Import;

use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Support\Facades\DB;

/**
 * A létező kontaktok KÖTEGES frissítése az importból — nyers `UPDATE … FROM (VALUES …)`.
 *
 * ## Miért nem `upsert`
 *
 * Az `INSERT … ON CONFLICT DO UPDATE` a közben soft-delete-elt (GDPR-anonimizált) sorra is rálép,
 * és személyes adatot ír egy törölt kontaktba. Az `UPDATE` itt `deleted_at IS NULL` feltétellel
 * megy, és `RETURNING`-gel visszaadja, mely címek frissültek ténylegesen: csak ezekhez készül
 * consent-esemény és listatagság.
 *
 * A státuszhoz, a címhez és az `external_id`-hoz NEM nyúl (a leiratkozás és a ventOS-szinkron
 * kulcsa erősebb). `fill`: a meglévő érték nyer; `overwrite`: a fájl NEM ÜRES értéke nyer.
 *
 * A nyers SQL a `ScopeBypassGuardTest` kivétel-listáján szerepel (mint a `Metering`): a
 * workspace-szűrő itt KÖTÖTT paraméter a kontextusból, nem elhagyható.
 */
final class ImportWriter
{
    public function __construct(private readonly WorkspaceContext $context) {}

    /**
     * @param  list<array<string, mixed>>  $rows  `email`, `first`, `last`, `attributes`
     * @return list<string> a ténylegesen frissített címek
     */
    public function updateExisting(array $rows, string $mode): array
    {
        if ($rows === []) {
            return [];
        }

        $existingWins = $mode === 'fill';

        $name = static fn (string $column): string => $existingWins
            ? "COALESCE(NULLIF(contacts.{$column}, ''), v.{$column})"
            : "COALESCE(NULLIF(v.{$column}, ''), contacts.{$column})";

        $attributes = $existingWins
            ? "v.attributes || COALESCE(contacts.attributes, '{}'::jsonb)"
            : "COALESCE(contacts.attributes, '{}'::jsonb) || v.attributes";

        $values = implode(', ', array_fill(0, count($rows), '(?::text, ?::text, ?::text, ?::jsonb)'));
        $bindings = [];

        foreach ($rows as $row) {
            $bindings[] = $row['email'];
            $bindings[] = $row['first'];
            $bindings[] = $row['last'];
            $bindings[] = ($row['attributes'] ?? []) === [] ? '{}' : json_encode($row['attributes'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        $bindings[] = now()->toDateTimeString();
        $bindings[] = $this->context->workspaceId();

        $sql = "UPDATE contacts SET first_name = {$name('first_name')}, last_name = {$name('last_name')}, "
            ."attributes = {$attributes}, updated_at = ?::timestamp "
            ."FROM (VALUES {$values}) AS v(email, first_name, last_name, attributes) "
            .'WHERE contacts.workspace_id = ? AND contacts.email = v.email AND contacts.deleted_at IS NULL '
            .'RETURNING contacts.email';

        // A `?` kötések sorrendje: VALUES-sorok → updated_at → workspace. A SET-ben az updated_at
        // jön ELŐBB a SQL-szövegben, mint a VALUES — ezért a kötéseket úgy rendezzük.
        $ordered = array_merge([$bindings[count($bindings) - 2]], array_slice($bindings, 0, -2), [$bindings[count($bindings) - 1]]);

        return array_map(static fn ($row): string => (string) $row->email, DB::select($sql, $ordered));
    }
}
