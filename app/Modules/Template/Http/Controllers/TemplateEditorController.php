<?php

declare(strict_types=1);

namespace App\Modules\Template\Http\Controllers;

use App\Modules\Domains\Models\Domain;
use App\Modules\Template\Models\Template;
use App\Modules\Template\Services\MjmlCompiler;
use App\Modules\Template\Services\MjmlWriter;
use App\Modules\Template\Services\PlainTextWriter;
use App\Modules\Template\Services\TemplateEditorSaver;
use App\Modules\Template\Services\TemplateOptimizer;
use App\Modules\Template\Services\TemplateTestSender;
use App\Modules\Template\Services\TemplateVersions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * A SZERKESZTŐ JSON-VÉGPONTJAI (v1.1): automatikus mentés, előnézet, optimalizálás,
 * másolat, teszt-küldés.
 *
 * Mind a web-stacken (session + CSRF + workspace), és mind a `template.create` képességhez
 * kötött. A fordítás MINDIG a szerveren történik: az előnézet és a mentés ugyanazt a
 * `MjmlWriter` → `MjmlCompiler` láncot hívja, mint a küldő út.
 */
final class TemplateEditorController
{
    /**
     * AUTOMATIKUS MENTÉS (debounce-olt). Verzió csak időközönként (`TemplateVersions`).
     */
    public function autosave(Request $request, string $uuid, TemplateEditorSaver $saver): JsonResponse
    {
        Gate::authorize('template.create');

        $template = Template::query()->where('uuid', $uuid)->firstOrFail();
        $data = $this->editorInput($request, $template);

        $version = $saver->save($template, $data, $request->boolean('manual') ? 'save' : 'autosave');

        return response()->json([
            'savedAt' => $template->updated_at?->toIso8601String(),
            'version' => $version?->number ?? (int) $template->versions()->max('number'),
            'html' => (string) $template->html,
        ]);
    }

    /**
     * ELŐNÉZET a MENTETLEN állapotból — a valódi fordítón, mentés nélkül.
     */
    public function preview(Request $request, string $uuid, MjmlWriter $writer, MjmlCompiler $compiler, PlainTextWriter $plain, TemplateEditorSaver $saver): JsonResponse
    {
        Gate::authorize('template.create');

        $template = Template::query()->where('uuid', $uuid)->firstOrFail();
        $data = $this->editorInput($request, $template);

        // A SÉMA itt is fut (a fordító a hibás fát nem fordítja), de a kötelező lábléc NEM: az
        // előnézet egy félkész levélről is kell.
        $saver->assertValid($data['blocks'], $data['styles'], false);

        $html = $compiler->compile($writer->write($data['blocks'], $data['preheader'], $data['styles']));

        return response()->json([
            'html' => $html,
            'text' => $plain->write($data['blocks'], $data['preheader']),
            'bytes' => strlen($html),
        ]);
    }

    public function optimize(Request $request, string $uuid, MjmlWriter $writer, MjmlCompiler $compiler, PlainTextWriter $plain, TemplateOptimizer $optimizer, TemplateEditorSaver $saver): JsonResponse
    {
        Gate::authorize('template.create');

        $template = Template::query()->where('uuid', $uuid)->firstOrFail();
        $data = $this->editorInput($request, $template);

        $saver->assertValid($data['blocks'], $data['styles'], false);

        $html = $compiler->compile($writer->write($data['blocks'], $data['preheader'], $data['styles']));
        $text = $plain->write($data['blocks'], $data['preheader']);

        return response()->json($optimizer->analyze($data['blocks'], $html, $text, $data['subject'], (bool) $template->marketing));
    }

    /**
     * MÁSOLAT: „Mentés másként" (a másolatra vált) és „Mentés sablonként" (a könyvtárba).
     */
    public function copy(Request $request, string $uuid, TemplateEditorSaver $saver, TemplateVersions $versions): JsonResponse
    {
        Gate::authorize('template.create');

        $source = Template::query()->where('uuid', $uuid)->firstOrFail();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        if (Template::query()->where('name', $validated['name'])->exists()) {
            throw ValidationException::withMessages(['name' => [__('ventmail.editor.nameTaken')]]);
        }

        $data = $this->editorInput($request, $source);
        $saver->assertValid($data['blocks'], $data['styles'], (bool) $source->marketing);

        $copy = DB::transaction(function () use ($validated, $data, $source, $versions): Template {
            $copy = Template::create([
                'name' => $validated['name'],
                'subject' => $data['subject'],
                'preheader' => $data['preheader'],
                'marketing' => (bool) $source->marketing,
                'blocks' => $data['blocks'],
                'styles' => $data['styles'],
                'starter_key' => $source->starter_key,
            ]);

            $versions->snapshot($copy, 'create');

            return $copy;
        });

        return response()->json(['uuid' => (string) $copy->uuid, 'url' => '/templates/'.$copy->uuid], 201);
    }

    /**
     * TESZT-KÜLDÉS — a PreSendGate-en át, csak a megengedett címzetteknek.
     *
     * Az `Idempotency-Key` KÖTELEZŐ (golden rule 5, `api.idempotency` middleware): a
     * kétszer megnyomott gomb vagy a hálózati ismétlés nem küld két levelet.
     */
    public function testSend(Request $request, string $uuid, TemplateTestSender $sender): JsonResponse
    {
        Gate::authorize('template.create');

        $template = Template::query()->where('uuid', $uuid)->firstOrFail();

        $validated = $request->validate([
            'recipients' => ['required', 'array', 'min:1', 'max:'.(int) config('template.test_send.max_recipients')],
            'recipients.*' => ['required', 'string', 'email:rfc'],
            'from_email' => ['required', 'string', 'email:rfc', 'max:255'],
        ]);

        $allowed = array_column($sender->allowedRecipients(), 'email');
        $recipients = array_values(array_unique(array_map(static fn ($r) => strtolower(trim((string) $r)), $validated['recipients'])));
        $foreign = array_values(array_diff($recipients, $allowed));

        if ($foreign !== []) {
            throw ValidationException::withMessages(['recipients' => [__('ventmail.editor.testSend.notAllowed')]]);
        }

        // A FELADÓ domainje a workspace VERIFIKÁLT domainje kell legyen — a kapu is ezt nézi,
        // de itt érthető mező-hibát adunk a „domain_unverified" elutasítás helyett.
        $domain = strtolower((string) substr(strrchr((string) $validated['from_email'], '@') ?: '', 1));

        if (! Domain::query()->where('name', $domain)->where('status', 'verified')->exists()) {
            throw ValidationException::withMessages(['from_email' => [__('ventmail.editor.testSend.unverifiedDomain')]]);
        }

        if ($template->html === null || trim((string) $template->html) === '') {
            throw ValidationException::withMessages(['template' => [__('ventmail.editor.testSend.empty')]]);
        }

        $result = $sender->send(
            $template,
            $recipients,
            strtolower(trim((string) $validated['from_email'])),
            (string) $request->attributes->get('idempotency_key'),
            Auth::user(),
        );

        return response()->json([
            'allowed' => $result->decision->allowed,
            'reason' => $result->decision->reason,
            'status' => $result->message?->status,
            'messageUuid' => $result->message?->uuid,
            'recipients' => $recipients,
        ], $result->decision->allowed ? 202 : 422);
    }

    /**
     * A szerkesztő bemenete — a hiányzó mező a sablon jelenlegi értéke.
     *
     * @return array{name: string, subject: ?string, preheader: ?string, blocks: array<int, mixed>, styles: ?array<string, mixed>}
     */
    private function editorInput(Request $request, Template $template): array
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:255'],
            'preheader' => ['sometimes', 'nullable', 'string', 'max:255'],
            'blocks' => ['sometimes', 'array'],
            'styles' => ['sometimes', 'nullable', 'array'],
        ]);

        $name = $validated['name'] ?? (string) $template->name;

        if ($name !== $template->name && $request->routeIs('templates.autosave')
            && Template::query()->where('name', $name)->whereKeyNot($template->getKey())->exists()) {
            throw ValidationException::withMessages(['name' => [__('ventmail.editor.nameTaken')]]);
        }

        $styles = array_key_exists('styles', $validated) ? $validated['styles'] : $template->styles;

        return [
            'name' => $name,
            'subject' => array_key_exists('subject', $validated) ? $validated['subject'] : $template->subject,
            'preheader' => array_key_exists('preheader', $validated) ? $validated['preheader'] : $template->preheader,
            'blocks' => array_key_exists('blocks', $validated) ? (array) $validated['blocks'] : (array) $template->blocks,
            'styles' => $styles === [] ? null : $styles,
        ];
    }
}
