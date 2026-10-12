<?php

declare(strict_types=1);

namespace App\Modules\Template\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Deliverability\Contracts\SeedListProvider;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Sending\Data\SendResult;
use App\Modules\Template\Models\Template;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Throwable;

/**
 * TESZT-KÜLDÉS a szerkesztőből (v1.1 · Optimalizálás panel).
 *
 * ## A PreSendGate-en át, mint minden más levél
 *
 * A teszt-levél a `SendMessage` actionön megy (golden rule 2: a kapu megkerülhetetlen).
 * Driver-hívás nincs, a küldési mód (`SENDING_LIVE`, seed-allowlist, marketing-kapcsoló)
 * ugyanúgy dönt, mint egy éles levélnél — seed-módban az allowlisten kívüli címzettet a
 * kapu elutasítja, és a felület ezt az OKOT mutatja meg, nem egy hamis „elküldve"-t.
 *
 * ## Kinek mehet
 *
 * CSAK a workspace TAGJAINAK címére és a platform seed-listájára (a Deliverability
 * `SeedListProvider`-e). Szabad cím nincs: a teszt-küldés különben egy consent-mentes,
 * tranzakciós csatornán futó tömeges küldő lenne — a „teszt" csak egy címke.
 *
 * ## A csatorna `transactional`
 *
 * A levél a feladó SAJÁT csapatának megy, nem egy kontaktnak: marketing-consent itt nem
 * értelmezett (a tag nem feliratkozó). A tárgy `[TESZT]` előtagot kap és `template-test`
 * címkét, tehát a naplóban és a statisztikában elkülönül.
 */
final class TemplateTestSender
{
    public function __construct(
        private readonly SendMessage $send,
        private readonly WorkspaceContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * A megengedett teszt-címzettek (kisbetűs, egyedi).
     *
     * @return list<array{email: string, kind: string}>
     */
    public function allowedRecipients(): array
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
            // dob): ilyenkor csak a tagok címe marad. A teszt-küldés nem áll meg emiatt.
        }

        $unique = [];

        foreach ([...$members, ...$seeds] as $entry) {
            if ($entry['email'] !== '' && ! isset($unique[$entry['email']])) {
                $unique[$entry['email']] = $entry;
            }
        }

        return array_values($unique);
    }

    /**
     * @param  list<string>  $recipients  a már ELLENŐRZÖTT (megengedett) címek
     */
    public function send(Template $template, array $recipients, string $fromEmail, string $idempotencyKey, ?User $actor): SendResult
    {
        $workspace = $this->context->workspace();
        $name = trim((string) ($actor?->name ?? ''));
        $parts = preg_split('/\s+/', $name) ?: [];

        $result = ($this->send)([
            'channel' => 'transactional',
            'from_email' => $fromEmail,
            'from_name' => (string) $workspace->name,
            'to' => $recipients,
            'subject' => __('ventmail.editor.testSend.subjectPrefix').' '.(string) ($template->subject ?: $template->name),
            'html' => (string) $template->html,
            'text' => (string) $template->text,
            'tags' => ['template-test'],
            // A MINTA-ADAT a merge-tagekhez: a küldő saját neve és a workspace. Feltételes
            // blokknál a minta-kontakt egyik szegmensnek sem tagja.
            'render_variables' => [
                'contact' => [
                    'first_name' => $parts[0] ?? '',
                    'last_name' => count($parts) > 1 ? (string) end($parts) : '',
                    'email' => $recipients[0] ?? '',
                    'company' => (string) $workspace->name,
                ],
                'workspace' => [
                    'name' => (string) $workspace->name,
                    'address' => (string) (($workspace->settings ?? [])['postal_address'] ?? ''),
                ],
            ],
            'idempotency_key' => 'template-test:'.$template->uuid.':'.$idempotencyKey,
        ]);

        $this->audit->log('template.test_sent', $template, context: [
            'recipients' => count($recipients),
            'allowed' => $result->decision->allowed,
            'reason' => $result->decision->reason,
            'message_uuid' => $result->message?->uuid,
        ]);

        return $result;
    }
}
