<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\Analytics\Models\CampaignEngagement;
use App\Modules\Audience\Models\Contact;
use App\Modules\Audit\Services\KeyedHash;
use App\Modules\Consent\Models\Consent;
use App\Modules\Sending\Services\DomainNormalizer;
use App\Modules\Tenancy\Models\Workspace;
use Illuminate\Support\Carbon;

/**
 * v1.1 · 5. modul — közös teszt-segédek (Studio: szegmens, tag, dashboard, kampány, űrlap).
 *
 * Statikus osztály, nem globális Pest-függvény: a függvénynevek a teljes suite-ban egy
 * névtérben élnek, és egy ütközés az EGÉSZ futást megállítja.
 */
final class StudioFixtures
{
    public static function user(Workspace $workspace, string $role = 'owner'): User
    {
        $user = User::create([
            'sso_issuer' => 'https://sso.teszt.invalid',
            'sso_subject' => 'studio-'.uniqid('', true),
            'name' => 'Studio Tesztelő',
            'email' => 'studio-'.uniqid().'@pelda.hu',
            'locale' => 'hu',
        ]);

        $workspace->members()->create(['user_id' => $user->getKey(), 'roles' => [$role]]);

        return $user;
    }

    /** Kontakt a KONTEXTUS workspace-ében (a hívó állítja be). */
    public static function contact(string $email, array $attributes = []): Contact
    {
        return Contact::create(array_merge(['email' => $email, 'status' => 'subscribed'], $attributes));
    }

    public static function consent(string $email, string $status = 'confirmed', ?string $source = 'form'): Consent
    {
        return Consent::query()->create([
            'email' => DomainNormalizer::listKey($email),
            'channel' => 'marketing',
            'status' => $status,
            'source' => $source,
        ]);
    }

    /** Engagement-sor a kontextus workspace-ében: kampány-hatókörrel, a címzett hash-ével. */
    public static function engagement(
        int $workspaceId,
        string $email,
        ?Carbon $openedAt = null,
        ?Carbon $clickedAt = null,
        string $scope = 'campaign:00000000-0000-7000-8000-000000000001',
        int $delivered = 1,
    ): void {
        CampaignEngagement::query()->withoutGlobalScopes()->insert([
            'workspace_id' => $workspaceId,
            'scope' => $scope,
            'variant_label' => '',
            'recipient_hash' => KeyedHash::of('email', DomainNormalizer::listKey($email)),
            'accepted' => 1,
            'delivered' => $delivered,
            'opens' => $openedAt === null ? 0 : 1,
            'clicks' => $clickedAt === null ? 0 : 1,
            'first_opened_at' => $openedAt,
            'last_opened_at' => $openedAt,
            'first_clicked_at' => $clickedAt,
            'last_clicked_at' => $clickedAt,
            'last_event_at' => $clickedAt ?? $openedAt ?? now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
