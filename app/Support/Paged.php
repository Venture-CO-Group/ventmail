<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * SZERVEROLDALI LAPOZÁS + KERESÉS egy névvel rendelkező listához (v1.1 · D284) — a közönség
 * listái/szegmensei és a kampány címzett-választója ugyanezt az alakot kapja:
 * `{items, page, last, total, search}`.
 *
 * A keresés `ilike` a `name` oszlopon; a `%`, `_` és `\` szó szerint értendő (escape-elve).
 */
final class Paged
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder  $query  (global scope-os, tenanthoz kötött modell-lekérdezés)
     * @param  callable(TModel): array<string, mixed>  $map
     * @return array{items: list<array<string, mixed>>, page: int, last: int, total: int, search: string}
     */
    public static function names(Request $request, Builder $query, string $prefix, callable $map, int $perPage = 10): array
    {
        $search = mb_substr(trim($request->string($prefix.'_search')->toString()), 0, 120);

        $page = $query
            ->when($search !== '', fn ($q) => $q->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%'))
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], $prefix.'_page')
            ->withQueryString();

        return [
            'items' => array_values(array_map($map, $page->items())),
            'page' => $page->currentPage(),
            'last' => $page->lastPage(),
            'total' => $page->total(),
            'search' => $search,
        ];
    }
}
