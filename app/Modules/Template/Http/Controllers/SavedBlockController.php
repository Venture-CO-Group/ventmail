<?php

declare(strict_types=1);

namespace App\Modules\Template\Http\Controllers;

use App\Modules\Template\Models\SavedBlock;
use App\Modules\Template\Services\BlockTreeValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * MENTETT BLOKKOK (v1.1 · sablonkönyvtár). Egy szakasz vagy egy tartalmi blokk, a
 * workspace-ben újrahasznosítható. Ugyanaz a validátor ellenőrzi, mint a sablont.
 */
final class SavedBlockController
{
    public function store(Request $request, BlockTreeValidator $validator): JsonResponse
    {
        Gate::authorize('template.create');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::in(['section', 'block'])],
            'block' => ['required', 'array'],
        ]);

        // A blokkot egy MINIMÁLIS fába tesszük, és azt validáljuk: így a `block` fajta is a
        // valódi szabályon (oszlop-gyereke-e) megy át, nem egy külön, gyengébb ellenőrzésen.
        $tree = $validated['kind'] === 'section'
            ? [$validated['block']]
            : [['type' => 'section', 'children' => [['type' => 'column', 'children' => [$validated['block']]]]]];

        $errors = $validator->errorsIn($tree);

        if ($errors !== []) {
            throw ValidationException::withMessages(['block' => $errors]);
        }

        $block = SavedBlock::query()->create([
            'name' => $validated['name'],
            'kind' => $validated['kind'],
            'block' => $validated['block'],
            'created_by' => Auth::id(),
        ]);

        return response()->json([
            'uuid' => (string) $block->uuid,
            'name' => (string) $block->name,
            'kind' => (string) $block->kind,
            'block' => (array) $block->block,
        ], 201);
    }

    public function destroy(Request $request, string $uuid): JsonResponse|RedirectResponse
    {
        Gate::authorize('template.create');

        SavedBlock::query()->where('uuid', $uuid)->firstOrFail()->delete();

        return $request->expectsJson() && ! $request->header('X-Inertia')
            ? response()->json(['deleted' => true])
            : back()->with('success', __('ventmail.editor.blockDeleted'));
    }
}
