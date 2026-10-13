<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Http\Controllers;

use App\Modules\Analytics\Models\CampaignEngagement;
use App\Modules\Analytics\Models\EngagementClientDaily;
use App\Modules\Analytics\Services\ClickMap;
use App\Modules\Audience\Models\Contact;
use App\Modules\Audit\Services\KeyedHash;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Domains\Models\Domain;
use App\Modules\Journey\Models\Journey;
use App\Modules\Journey\Models\JourneyStep;
use App\Modules\Logs\Models\DomainDailyStat;
use App\Modules\Template\Models\Template;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * KIZÁRÓLAG fejlesztői és teszt-környezetben (a `/dev/seed-logs` mintája): DEMÓ-ADAT az
 * analitika böngésző-tesztjéhez (v1.1 · 8. modul) — egy kiküldött kampány nyitás/kattintás
 * idősorral, eszköz/kliens-bontással, klikk-térképpel; közönség-növekedés; egy journey
 * csomópont-számokkal; domain-statisztika bounce-típusokkal.
 *
 * Levelet NEM küld (a számok közvetlenül a származék-táblákba kerülnek). Idempotens: a második
 * hívás csak átirányít. Két független kapcsoló védi, mint a dev-belépést.
 */
final class DevSeedAnalyticsController
{
    public const CAMPAIGN_NAME = 'Őszi újdonságok – hírlevél a teljes törzsvendégkörnek (demó, nagyon hosszú kampánynév a tördelés ellenőrzéséhez)';

    public function __invoke(WorkspaceContext $context): RedirectResponse
    {
        abort_unless(
            config('identity.dev_login_enabled') && ! app()->isProduction() && app()->environment('local', 'testing'),
            404
        );

        if (Campaign::query()->where('name', self::CAMPAIGN_NAME)->exists()) {
            return redirect('/analytics?range=90');
        }

        $workspaceId = $context->workspaceId();
        $domain = Domain::firstOrCreate(['name' => 'pelda.hu'], ['status' => 'verified', 'verified_at' => now()]);

        DB::transaction(function () use ($workspaceId, $domain): void {
            $template = Template::create([
                'name' => 'Demó hírlevél (analitika)',
                'subject' => 'Őszi újdonságok',
                'blocks' => [[
                    'type' => 'section',
                    'children' => [['type' => 'column', 'children' => [
                        ['type' => 'text', 'attrs' => ['content' => 'Őszi újdonságok a Nádor Kávéházban']],
                        ['type' => 'button', 'attrs' => ['content' => 'Asztalfoglalás', 'href' => 'https://nador-kavehaz.example/foglalas']],
                        ['type' => 'button', 'attrs' => ['content' => 'Teljes őszi kínálat', 'href' => 'https://nador-kavehaz.example/osz']],
                        ['type' => 'unsubscribe', 'attrs' => []],
                    ]]],
                ]],
            ]);

            $campaign = Campaign::create([
                'template_id' => $template->getKey(),
                'name' => self::CAMPAIGN_NAME,
                'subject' => 'Őszi újdonságok',
                'from_email' => 'no-reply@pelda.hu',
                'target_lists' => [],
            ]);
            $started = now()->subDays(6)->startOfHour();
            $campaign->forceFill(['status' => 'sent', 'started_at' => $started, 'finished_at' => $started->copy()->addHour(), 'recipient_count' => 400])->saveQuietly();

            $scope = 'campaign:'.$campaign->uuid;
            $rows = [];

            for ($i = 0; $i < 400; $i++) {
                $opened = $i % 5 !== 0 ? $started->copy()->addMinutes(20 + $i * 17 % 7000) : null;
                $clicked = $opened !== null && $i % 4 === 0 ? $opened->copy()->addMinutes(3) : null;
                $rows[] = [
                    'workspace_id' => $workspaceId, 'scope' => $scope, 'variant_label' => '',
                    'recipient_hash' => KeyedHash::of('email', 'demo-'.$i.'@example.test'),
                    'accepted' => 1, 'delivered' => $i % 50 === 0 ? 0 : 1, 'bounced' => $i % 50 === 0 ? 1 : 0,
                    'opens' => $opened ? 1 : 0, 'clicks' => $clicked ? 1 : 0,
                    'first_opened_at' => $opened, 'last_opened_at' => $opened,
                    'first_clicked_at' => $clicked, 'last_clicked_at' => $clicked,
                    'last_event_at' => $clicked ?? $opened ?? $started, 'created_at' => now(), 'updated_at' => now(),
                ];
            }

            foreach (array_chunk($rows, 200) as $chunk) {
                CampaignEngagement::query()->insert($chunk);
            }

            $map = app(ClickMap::class);

            foreach (['https://nador-kavehaz.example/foglalas' => 60, 'https://nador-kavehaz.example/osz' => 25] as $url => $n) {
                for ($i = 0; $i < $n; $i++) {
                    $map->record((string) Str::uuid7(), (string) $campaign->uuid, null, $url, 'demo-'.$i.'@example.test');
                }
            }

            $clients = [['mobile', 'apple_mail', 120], ['unknown', 'apple_mpp', 90], ['unknown', 'gmail', 70], ['desktop', 'outlook', 40], ['mobile', 'android', 20]];

            foreach ($clients as [$device, $client, $total]) {
                EngagementClientDaily::query()->insert([
                    'workspace_id' => $workspaceId, 'scope' => $scope, 'day' => $started->toDateString(), 'kind' => 'open',
                    'device' => $device, 'client' => $client, 'total' => $total, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            for ($d = 0; $d < 30; $d++) {
                $sent = 900 + ($d * 37) % 300;
                $counts = [
                    'sent' => $sent, 'delivered' => $sent - 6 - $d % 4, 'bounced' => 6 + $d % 4, 'complained' => $d % 7 === 0 ? 1 : 0,
                    'opened' => (int) ($sent * 0.4), 'clicked' => (int) ($sent * 0.08),
                    'bounced_hard' => 2, 'bounced_soft' => 2 + $d % 4, 'bounced_mailbox_full' => 1, 'bounced_blocked' => 1,
                    'bounced_spam' => 0, 'bounced_policy' => 0,
                ];
                DomainDailyStat::query()->upsert(
                    [['workspace_id' => $workspaceId, 'domain_id' => $domain->getKey(), 'day' => now()->subDays($d)->toDateString(), ...$counts, 'created_at' => now(), 'updated_at' => now()]],
                    ['workspace_id', 'domain_id', 'day'],
                    array_keys($counts),
                );
            }

            for ($i = 0; $i < 60; $i++) {
                $contact = Contact::create(['email' => 'seed-analytics-'.$i.'@example.test', 'status' => $i % 9 === 0 ? 'unsubscribed' : 'subscribed']);
                $contact->forceFill([
                    'created_at' => now()->subDays($i % 80),
                    'unsubscribed_at' => $i % 9 === 0 ? now()->subDays(($i % 80) / 2) : null,
                ])->saveQuietly();
            }

            $journey = Journey::create([
                'name' => 'Üdvözlő sorozat (demó)',
                'trigger_type' => 'api_event',
                'graph' => [
                    'nodes' => [
                        ['id' => 'trigger', 'type' => 'api_event', 'label' => 'Trigger: feliratkozás', 'position' => ['x' => 0, 'y' => 0], 'config' => ['event' => 'signup']],
                        ['id' => 'welcome', 'type' => 'send_email', 'label' => 'Üdvözlő e-mail', 'position' => ['x' => 0, 'y' => 120], 'config' => ['template' => (string) $template->uuid]],
                        ['id' => 'goal', 'type' => 'goal', 'label' => 'Cél: első vásárlás', 'position' => ['x' => 0, 'y' => 240], 'config' => []],
                    ],
                    'edges' => [
                        ['id' => 'e1', 'source' => 'trigger', 'sourceHandle' => 'out', 'target' => 'welcome'],
                        ['id' => 'e2', 'source' => 'welcome', 'sourceHandle' => 'out', 'target' => 'goal'],
                    ],
                ],
            ]);
            $journey->forceFill(['entered_count' => 1842])->saveQuietly();

            foreach (['welcome' => ['send_email', ['template' => (string) $template->uuid], 1628], 'goal' => ['goal', [], 276]] as $key => [$type, $config, $executed]) {
                $step = JourneyStep::create(['journey_id' => $journey->getKey(), 'type' => $type, 'config' => $config, 'node_key' => $key]);
                $step->forceFill(['executed_count' => $executed])->saveQuietly();
            }
        });

        return redirect('/analytics?range=90');
    }
}
