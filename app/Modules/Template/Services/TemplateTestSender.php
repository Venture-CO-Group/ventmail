<?php

declare(strict_types=1);

namespace App\Modules\Template\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Sending\Data\SendResult;
use App\Modules\Template\Models\Template;
use App\Modules\Tenancy\Services\WorkspaceContext;

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
 * ## A csatorna a sablon fajtája szerint (D52, a D45 helyett)
 *
 * A marketing sablon tesztje `campaign`, a tranzakciósé `transactional` csatornán megy —
 * a két pool (tx/mk) nem keveredhet. A marketing tesztet a consent-kapu csak akkor engedi,
 * ha MINDEN címzett workspace-tag vagy seed-cím (`TestRecipients`); kikapcsolt marketing-
 * küldésnél (`SENDING_MARKETING_ENABLED=false`) elutasul. A tárgy `[TESZT]` előtagot és
 * `template-test` címkét kap.
 */
final class TemplateTestSender
{
    public function __construct(
        private readonly SendMessage $send,
        private readonly WorkspaceContext $context,
        private readonly AuditLogger $audit,
        private readonly TestRecipients $recipients,
    ) {}

    /** @return list<array{email: string, kind: string}> */
    public function allowedRecipients(): array
    {
        return $this->recipients->all();
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
            // D52: a MARKETING sablon tesztje a `campaign` csatornán (mk pool, List-
            // Unsubscribe) megy — a tx és az mk forgalom nem keveredik. A consent-kapu a
            // `template_test` eredetű, belső címzettű levélre explicit kivételt ad.
            'channel' => $template->marketing ? 'campaign' : 'transactional',
            'options' => ['studio_origin' => 'template_test'],
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
            // A kulcs HASH-elve: a hívó kulcsa 255 karakteres is lehet, a prefixszel együtt a
            // `messages.idempotency_key` oszlopon túlnyúlna.
            'idempotency_key' => self::key((string) $template->uuid, $idempotencyKey),
        ]);

        $this->audit->log('template.test_sent', $template, context: [
            'recipients' => count($recipients),
            'allowed' => $result->decision->allowed,
            'reason' => $result->decision->reason,
            'message_uuid' => $result->message?->uuid,
        ]);

        return $result;
    }

    public static function key(string $templateUuid, string $idempotencyKey): string
    {
        return 'template-test:'.hash('sha256', $templateUuid.'|'.$idempotencyKey);
    }
}
