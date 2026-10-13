<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\Audience\Models\ContactList;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Domains\Models\Domain;
use App\Modules\Sending\Models\Message;
use App\Modules\Template\Models\Template;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;

/**
 * v1.1 · 8. modul — közös teszt-segédek (analitika, riport, branding). Statikus osztály (a Pest
 * fájl-függvényei globálisak, egy névütközés az egész suite-ot megállítja).
 */
final class AnalyticsFixtures
{
    /** Workspace + tag a megadott szereppel; a kontextus a workspace-re áll. */
    public static function tenant(string $role = 'owner'): array
    {
        $workspace = Workspace::factory()->create();
        $user = StudioFixtures::user($workspace, $role);
        app(WorkspaceContext::class)->set($workspace);

        return [$workspace, $user];
    }

    public static function template(array $blocks = []): Template
    {
        return Template::create([
            'name' => 'Riport-sablon '.bin2hex(random_bytes(3)),
            'subject' => 'Szia',
            'blocks' => $blocks !== [] ? $blocks : [[
                'type' => 'section',
                'children' => [['type' => 'column', 'children' => [
                    ['type' => 'text', 'attrs' => ['content' => 'Szia!']],
                    ['type' => 'button', 'attrs' => ['content' => 'Foglalás', 'href' => 'https://pelda.hu/foglalas?utm_source=hirlevel&email=a@b.hu']],
                    ['type' => 'button', 'attrs' => ['content' => 'Kínálat', 'href' => 'https://pelda.hu/osz']],
                    ['type' => 'unsubscribe', 'attrs' => []],
                ]]],
            ]],
        ]);
    }

    /** Kiküldött kampány a KONTEXTUS workspace-ében. */
    public static function campaign(array $attributes = [], ?Template $template = null): Campaign
    {
        $list = ContactList::create(['name' => 'Lista '.bin2hex(random_bytes(3))]);
        $template ??= self::template();

        $campaign = Campaign::create([
            'template_id' => $template->getKey(),
            'name' => $attributes['name'] ?? 'Kampány '.bin2hex(random_bytes(3)),
            'subject' => 'Tárgy',
            'from_email' => 'no-reply@pelda.hu',
            'target_lists' => [(string) $list->uuid],
        ]);

        $campaign->forceFill(array_merge(['status' => 'sent', 'started_at' => now()->subDay()], array_diff_key($attributes, ['name' => true])))->saveQuietly();

        return $campaign->refresh();
    }

    /** Követett (tracking-domainnel) üzenet egy kampányhoz. */
    public static function trackedMessage(Campaign $campaign, string $recipient = 'nyito@pelda.hu', array $tags = []): Message
    {
        $domain = Domain::query()->firstOrCreate(['name' => 'pelda.hu'], ['status' => 'verified', 'verified_at' => now()]);
        $domain->forceFill(['tracking_domain' => 'email.pelda.hu', 'tracking_status' => 'verified', 'tracking_enabled' => true])->save();

        return Message::create([
            'domain_id' => $domain->getKey(),
            'channel' => 'campaign',
            'from_email' => 'no-reply@pelda.hu',
            'subject' => 'Tárgy',
            'recipients' => [$recipient],
            'tags' => $tags !== [] ? $tags : ['campaign:'.$campaign->uuid],
            'status' => 'delivered',
        ]);
    }

    public static function as(User $user, Workspace $workspace): array
    {
        return ['user' => $user, 'session' => ['workspace_uuid' => $workspace->uuid]];
    }
}
