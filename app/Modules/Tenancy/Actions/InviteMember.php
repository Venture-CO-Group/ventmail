<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Enums\Role;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Sending\Services\DomainNormalizer;
use App\Modules\Tenancy\Models\WorkspaceInvitation;
use App\Modules\Tenancy\Models\WorkspaceMember;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * TAG MEGHÍVÁSA e-mail-cím alapján (spec 3.2).
 *
 * ## Miért nem hoz létre felhasználót
 *
 * Az identitás gazdája a ventSSO (golden rule 1). A meghívó CSAK azt rögzíti, hogy egy
 * címhez milyen szerep jár, ha az a cím belép — a `users` sort a bejelentkezés hozza létre,
 * a `RedeemInvitations` pedig ott váltja be a meghívót.
 *
 * ## A szerep-korlát a MEGHÍVÁSKOR dől el
 *
 * Az `AssignWorkspaceRoles::assertGrantable()`-t hívja, nem egy másolatot: különben az
 * `admin` `owner`-meghívót írhatna, és a szerepet a beváltás adná ki — ellenőrzés nélkül,
 * egy olyan pillanatban, amikor a meghívó már nincs is a képben.
 */
final class InviteMember
{
    /** A nyitott meghívó élettartama (napokban) — konfigurálható, alap 14. */
    private function ttlDays(): int
    {
        return max(1, (int) config('tenancy.invitations.ttl_days', 14));
    }

    public function __construct(
        private readonly WorkspaceContext $context,
        private readonly AssignWorkspaceRoles $roles,
        private readonly AuditLogger $audit,
    ) {}

    /** @param list<string> $roles */
    public function __invoke(string $email, array $roles): WorkspaceInvitation
    {
        $validated = array_map(fn (Role $role) => $role->value, $this->roles->assertGrantable($roles));

        /*
         | A CÍM HOZZÁFÉRÉSI KULCSKÉNT normalizálódik (`authKey`), nem lista-kulcsként.
         |
         | A `listKey()` a helyi részen `mb_strtolower`-t futtat, ami NEM injektív: a
         | `bo\u{212A}@corp.com` (KELVIN SIGN) is `bok@corp.com`-má válik — vagyis egy
         | `bok@corp.com`-nak szóló admin-meghívót egy MÁSIK postafiók tulajdonosa
         | válthatta volna be. Suppression-kulcsnál a túl-illesztés biztonságos irányba
         | téved, belépési jogcímnél nyitja a kaput.
         |
         | Az `authKey()` ezért HANGOSAN elutasít mindent, aminek nincs tiszta ASCII
         | leképezése — a hívó hibát kap, nem félreillesztést.
         */
        $email = DomainNormalizer::authKey($email);

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE) || str_contains($email, '[')) {
            throw new RuntimeException(__('ventmail.members.invalidEmail'));
        }

        // AKI MÁR TAG, azt nem hívjuk meg újra: a meghívó beváltása felülírná a jelenlegi
        // szerepeit (akár lefelé is), és a felület azt mutatná, hogy „függőben" van valaki,
        // aki már bent van.
        // A `users.email` a ventSSO claimjéből származik, ÉRINTETLENÜL (a `SyncSsoUser`
        // nem normalizál) — a nyers egyenlőség ezért `Bob@Corp.com` meglévő tag mellett
        // átengedte a `bob@corp.com` meghívót. Az összehasonlítás a MI kulcsunk szerint
        // megy, adatbázis-oldali kisbetűsítéssel.
        $alreadyMember = WorkspaceMember::query()
            ->where('workspace_id', $this->context->workspaceId())
            ->whereExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('users')
                ->whereColumn('users.id', 'workspace_members.user_id')
                ->whereRaw('lower(users.email) = ?', [$email]))
            ->exists();

        if ($alreadyMember) {
            throw new RuntimeException(__('ventmail.members.alreadyMember'));
        }

        $invitation = DB::transaction(function () use ($email, $validated): WorkspaceInvitation {
            // AZ ÚJRAHÍVÁS a nyitott meghívót FRISSÍTI, nem duplikálja (részleges unique
            // index őrzi): a szerepek és a lejárat is az újabb döntést követik.
            $invitation = WorkspaceInvitation::query()
                ->where('email', $email)
                ->whereNull('accepted_at')
                ->first();

            if ($invitation === null) {
                $invitation = new WorkspaceInvitation(['email' => $email]);
            }

            $invitation->fill([
                'roles' => $validated,
                'invited_by_user_id' => Auth::id(),
                'expires_at' => now()->addDays($this->ttlDays()),
            ])->save();

            return $invitation->fresh();
        });

        // Golden rule 11: a meghívás jogot oszt, tehát auditált — a beváltás is az lesz.
        $this->audit->log('member.invited', $invitation, after: [
            'email' => $email,
            'roles' => $validated,
        ]);

        $this->notify($invitation);

        return $invitation;
    }

    /**
     * ÉRTESÍTŐ LEVÉL — TRANZAKCIÓS csatornán, és BEST-EFFORT.
     *
     * Tranzakciós, mert nem marketing: a címzett a saját hozzáférését kapja meg, nem
     * ajánlatot. A `SendMessage`-en megy, tehát mind a hat kapun át — nincs kiskapu.
     *
     * Best-effort, mert a küldés MEGBUKHAT rajtunk kívül álló okból (a workspace-nek még
     * nincs hitelesített domainje). A meghívó ettől ÉRVÉNYES marad: a felületen látszik, a
     * meghívott a belépéssel akkor is bekerül. A levél elmaradását naplózzuk — a hívó
     * felület pedig figyelmeztetést mutat, hogy a címzettet másképp kell értesíteni.
     */
    private function notify(WorkspaceInvitation $invitation): void
    {
        $from = config('mail.from.address');

        if (! is_string($from) || $from === '') {
            return;
        }

        $workspace = $this->context->workspace()->name;
        $url = rtrim((string) config('app.url'), '/').'/dashboard';

        try {
            app(SendMessage::class)([
                'channel' => 'transactional',
                'from_email' => $from,
                'to' => [(string) $invitation->email],
                // A LEVÉL SZÖVEGE fordításból jön: a címzett idegen szervezet tagja, és
                // nem feltétlenül beszél magyarul. A workspace nyelve a legjobb tippünk —
                // a meghívott saját nyelvét még nem ismerjük (nincs `users` sora).
                'subject' => __('ventmail.members.mailSubject', ['workspace' => $workspace]),
                'text' => __('ventmail.members.mailText', ['workspace' => $workspace, 'url' => $url]),
                'html' => '<p>'.e(__('ventmail.members.mailIntro', ['workspace' => $workspace])).'</p>'
                    .'<p><a href="'.e($url).'">'.e($url).'</a></p>',
                // Egy meghívóhoz EGY levél: az újrahívás ugyanazt a sort frissíti, tehát a
                // kulcsba a lejárat is belemegy — új meghívás, új levél.
                'idempotency_key' => 'invite:'.hash('sha256', (string) $invitation->uuid.'|'.$invitation->expires_at?->timestamp),
            ]);
        } catch (Throwable $e) {
            // A KIVÉTEL ÜZENETE NEM kerül a naplóba: egy `QueryException` szövege a kötött
            // paramétereket is tartalmazza — mérve a meghívott e-mail-címét is —, és az
            // alkalmazás-napló nem a PII helye. A típus és a kód elég a diagnózishoz.
            Log::warning('A meghívó levele nem ment ki.', [
                'invitation' => (string) $invitation->uuid,
                'exception' => $e::class,
                'code' => (string) $e->getCode(),
            ]);
        }
    }
}
