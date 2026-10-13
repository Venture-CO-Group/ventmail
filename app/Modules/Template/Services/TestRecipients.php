<?php

declare(strict_types=1);

namespace App\Modules\Template\Services;

use App\Models\User;
use App\Modules\Deliverability\Contracts\SeedListProvider;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Throwable;

/**
 * A SABLON-TESZT megengedett címzettjei: a workspace tagjai és a platform seed-címei.
 *
 * Egy helyen, mert KÉT hívó kérdezi: a szerkesztő (mit kínál fel) és a consent-kapu
 * (D52: a `template_test` eredetű marketing levél consent-kivétele CSAK ezekre a címekre
 * él). Ha a kettő külön listát tartana, a kapu kivétele elcsúszhatna a felülettől.
 */
final class TestRecipients
{
    public function __construct(private readonly WorkspaceContext $context) {}

    /** @return list<array{email: string, kind: string}> */
    public function all(): array
    {
        $workspace = $this->context->workspace();

        $members = User::query()
            ->whereIn('id', $workspace->members()->pluck('user_id'))
            ->orderBy('email')
            ->pluck('email')
            ->map(static fn ($email) => ['email' => strtolower(trim((string) $email)), 'kind' => 'member'])
            ->all();

        $seeds = [];

        try {
            foreach (app(SeedListProvider::class)->addresses() as $seed) {
                $seeds[] = ['email' => strtolower(trim($seed->address)), 'kind' => 'seed'];
            }
        } catch (Throwable) {
            // ÉLESBEN nincs seed-szolgáltató (a Deliverability provider szándékosan hibát
            // dob): ilyenkor csak a tagok címe marad.
        }

        $unique = [];

        foreach ([...$members, ...$seeds] as $entry) {
            if ($entry['email'] !== '' && ! isset($unique[$entry['email']])) {
                $unique[$entry['email']] = $entry;
            }
        }

        return array_values($unique);
    }

    /** @param list<string> $emails */
    public function allows(array $emails): bool
    {
        $allowed = array_column($this->all(), 'email');

        foreach ($emails as $email) {
            if (! in_array(strtolower(trim($email)), $allowed, true)) {
                return false;
            }
        }

        return $emails !== [];
    }
}
