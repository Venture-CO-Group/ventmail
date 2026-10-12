<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Models\User;
use App\Modules\Tenancy\Models\Workspace;

/**
 * A profil-beállítások KANONIKUS alakja: alapértékek, megengedett formátumok, olvasás.
 *
 * Egy helyen él, hogy a kontroller, a megosztott Inertia-props és a későbbi értesítő/
 * formázó kódok ugyanazt a szótárat használják.
 */
final class ProfileSettings
{
    /** ymd: 2026. 10. 12. · dmy: 12/10/2026 · mdy: 10/12/2026 · iso: 2026-10-12 */
    public const DATE_FORMATS = ['ymd', 'dmy', 'mdy', 'iso'];

    /** space_comma: 1 234 567,89 · comma_dot: 1,234,567.89 · dot_comma: 1.234.567,89 · apostrophe_dot: 1’234’567.89 */
    public const NUMBER_FORMATS = ['space_comma', 'comma_dot', 'dot_comma', 'apostrophe_dot'];

    /** Az alkalmazás-értesítések és alapértékük. */
    public const NOTIFICATION_DEFAULTS = [
        'import_finished' => true,
        'report_ready' => true,
        'deliverability_alerts' => true,
        'weekly_summary' => false,
    ];

    /** @return array<string, mixed> */
    public static function for(User $user): array
    {
        $default = $user->raw('default_workspace_id') === null
            ? null
            : Workspace::query()->whereKey($user->raw('default_workspace_id'))->value('uuid');

        return [
            'locale' => $user->preferredLocale(),
            'localeSource' => $user->raw('locale_source'),
            'timezone' => $user->raw('timezone') ?? (string) config('app.timezone'),
            'date_format' => $user->raw('date_format') ?? 'ymd',
            'number_format' => $user->raw('number_format') ?? 'space_comma',
            'notifications' => self::normaliseNotifications([], is_array($user->raw('notification_prefs')) ? $user->notification_prefs : null),
            'default_workspace' => $default === null ? null : (string) $default,
        ];
    }

    /**
     * @param  array<string, bool>  $incoming
     * @param  array<string, mixed>|null  $stored
     * @return array<string, bool>
     */
    public static function normaliseNotifications(array $incoming, ?array $stored): array
    {
        $result = [];

        foreach (self::NOTIFICATION_DEFAULTS as $key => $default) {
            $result[$key] = array_key_exists($key, $incoming)
                ? $incoming[$key]
                : (is_array($stored) && array_key_exists($key, $stored) ? (bool) $stored[$key] : $default);
        }

        return $result;
    }
}
