<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Services\ProfileSettings;
use App\Modules\Tenancy\Models\WorkspaceMember;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PROFILBEÁLLÍTÁSOK — kizárólag a ventMail-specifikus, APP-szintű beállítások (D11).
 *
 * A név, az e-mail, a jelszó és a 2FA a ventSSO-ban él: az oldal ezekre csak LINKEL
 * (`ssoAccountUrl`), és a mentés szándékosan nem is olvas ilyen mezőt — a validált
 * tömbből csak az alább felsorolt kulcsok jutnak a modellre.
 */
final class ProfileController
{
    public function show(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Identity/Profile', [
            'settings' => ProfileSettings::for($user),
            'timezones' => DateTimeZone::listIdentifiers(),
            'dateFormats' => ProfileSettings::DATE_FORMATS,
            'numberFormats' => ProfileSettings::NUMBER_FORMATS,
            'notificationKeys' => array_keys(ProfileSettings::NOTIFICATION_DEFAULTS),
            'ssoAccountUrl' => (string) config('identity.sso_account_url'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $memberOf = WorkspaceMember::query()
            ->where('user_id', $user->getKey())
            ->with('workspace')
            ->get()
            ->map(fn (WorkspaceMember $member) => $member->workspace)
            ->filter();

        $validated = $request->validate([
            'locale' => ['sometimes', 'string', Rule::in((array) config('app.supported_locales', []))],
            'timezone' => ['sometimes', 'nullable', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            'date_format' => ['sometimes', 'nullable', 'string', Rule::in(ProfileSettings::DATE_FORMATS)],
            'number_format' => ['sometimes', 'nullable', 'string', Rule::in(ProfileSettings::NUMBER_FORMATS)],
            'notifications' => ['sometimes', 'array', 'max:'.count(ProfileSettings::NOTIFICATION_DEFAULTS)],
            'notifications.*' => ['boolean'],
            // CSAK saját tagság: más tenant UUID-ját nem fogadjuk el alapértelmezettnek.
            'default_workspace' => ['sometimes', 'nullable', 'string', Rule::in($memberOf->pluck('uuid')->map(fn ($uuid) => (string) $uuid)->all())],
        ]);

        $changes = [];

        if (array_key_exists('locale', $validated)
            && ($validated['locale'] !== app()->getLocale() || $user->raw('locale_source') !== 'user')) {
            // Tudatos választás: az SSO többé nem írja felül.
            $changes['locale'] = $validated['locale'];
            $changes['locale_source'] = 'user';
        }

        foreach (['timezone', 'date_format', 'number_format'] as $key) {
            if (array_key_exists($key, $validated)) {
                $changes[$key] = $validated[$key];
            }
        }

        if (array_key_exists('notifications', $validated)) {
            $changes['notification_prefs'] = ProfileSettings::normaliseNotifications(
                array_map('boolval', (array) $validated['notifications']),
                $user->notificationPrefs(),
            );
        }

        if (array_key_exists('default_workspace', $validated)) {
            $changes['default_workspace_id'] = $validated['default_workspace'] === null
                ? null
                : $memberOf->first(fn ($workspace) => (string) $workspace->uuid === $validated['default_workspace'])?->getKey();
        }

        $before = array_combine(array_keys($changes), array_map(fn ($key) => $user->raw($key), array_keys($changes)));
        $user->forceFill($changes)->save();

        // AUDIT: csak a megváltozott KULCSOK, értékek nélkül (nincs PII/érték a naplóban).
        $changed = array_keys(array_filter(
            $changes,
            fn ($value, $key) => ($before[$key] ?? null) != $value,
            ARRAY_FILTER_USE_BOTH,
        ));

        if ($changed !== []) {
            app(AuditLogger::class)->log('user.settings.updated', null, context: ['changed' => $changed]);
        }

        if (isset($changes['locale'])) {
            app()->setLocale($changes['locale']);
        }

        return back()->with('success', __('ventmail.profile.saved'));
    }
}
