<?php

declare(strict_types=1);

namespace App\Modules\Messages\Http\Controllers;

use App\Modules\Api\Support\ApiError;
use App\Modules\Billing\Contracts\VentPayClient;
use App\Modules\Billing\Enums\Feature;
use App\Modules\Billing\Services\EntitlementChecker;
use App\Modules\Identity\Models\ApiKey;
use App\Modules\IpPools\Models\IpPool;
use App\Modules\Messages\Data\MailgunPayload;
use App\Modules\Sending\Actions\SendMessage;
use App\Modules\Sending\Gate\GateReason;
use App\Modules\StoredTemplates\Models\StoredTemplate;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use InvalidArgumentException;

/**
 * `POST /v1/messages` — a Mailgun Messages API paritása (spec 8.).
 *
 * A kontroller SEMMILYEN küldési döntést nem hoz: értelmezi a kérést, majd átadja a
 * `SendMessage` actionnek, ami a PreSendGate-en át küld. A gate strukturált `reason` kódja
 * VÁLTOZATLANUL kerül a válaszba — így a hívó ugyanazt látja, mint az üzenet-trace (A3).
 */
final class SendMessageController
{
    public function __invoke(
        Request $request,
        SendMessage $send,
        VentPayClient $ventPay,
        WorkspaceContext $workspace,
    ): JsonResponse {
        /** @var ApiKey|null $apiKey */
        $apiKey = $request->attributes->get('api_key');

        // A csatolmányt ma nem támogatjuk — a NÉMA eldobás rosszabb szerződés, mint a hiba:
        // a hívó 202-t kapna, és a levél csatolmány nélkül menne ki.
        if ($request->hasFile('attachment') || $request->hasFile('inline')) {
            return ApiError::badRequest(
                'UNSUPPORTED_ATTACHMENT',
                'A csatolmány-küldés még nem támogatott (ventSec ClamAV-lánc: A4). '
                .'Használj hivatkozást a tartalomra.'
            );
        }

        try {
            $payload = MailgunPayload::fromRequest($request->all());
        } catch (InvalidArgumentException $e) {
            [$code, $message] = array_pad(explode('|', $e->getMessage(), 2), 2, '');

            return ApiError::badRequest($code, $message);
        }

        // Tárolt sablon: a `template=` a Mailgun paritásának része.
        //
        // A sablon törzse VÁLTOZATLANUL megy tovább — NEM renderelünk itt. A korábbi
        // elő-renderelés két passt csinált: az itt behelyettesített érték a küldéskor
        // MÉGEGYSZER átment a `{{ }}` / `%recipient.%` fázison (a `htmlspecialchars` a `{`
        // és a `%` karaktert nem escape-eli), tehát a hívó saját értéke másodszor is
        // kiértékelődött — kész gadget a B1 kontakt-attribútumaihoz. Egy pass, egy helyen:
        // a renderelés a küldési úton történik, a megosztott és a címzettenkénti
        // változókkal EGYSZERRE.
        $html = $payload->html;
        $text = $payload->text;
        $subject = $payload->subject;
        $options = $payload->options;

        if ($payload->template !== null) {
            $template = StoredTemplate::query()->where('name', $payload->template)->first();
            $version = $template?->activeVersion();

            if ($version === null) {
                return ApiError::badRequest(
                    'UNKNOWN_TEMPLATE',
                    "Ismeretlen vagy aktív verzió nélküli sablon: {$payload->template}"
                );
            }

            $html = $version->body;
            $text = $version->body_text ?? $text;

            // A KÉRÉS TÁRGYA MINDIG NYER. Csak ha nincs, megy a sablonverzió tárgya — a
            // helyőrzők a küldési úton töltődnek (törzsével azonos változókkal), ezért a
            // jelölő: csak a sablonból jövő tárgyat renderelünk, a hívó sajátját nem.
            if (($subject === null || trim($subject) === '') && $version->subject !== null) {
                $subject = $version->subject;
                $options['subject_from_template'] = true;
            }
        }

        $ipPoolId = null;

        if ($payload->ipPool !== null) {
            // A SAJÁT pool előnyben a platform-szintűvel szemben: névütközésnél különben
            // nem lenne determinisztikus, melyiket kapja a tenant.
            $pool = IpPool::query()
                ->where('name', $payload->ipPool)
                ->orderByRaw('workspace_id is null')
                ->first();

            if ($pool === null) {
                return ApiError::badRequest('UNKNOWN_IP_POOL', "Ismeretlen IP-pool: {$payload->ipPool}");
            }

            // Dedikált pool ENTITLEMENT-hez kötött (ventPay): különben bárki rátehetné a
            // forgalmát egy fizetett, jó reputációjú IP-re.
            // Az ALANY a HÍVÓ, nem a pool tulajdonosa: platform-szintű poolnál a
            // `workspace_id` NULL, tehát a jogosultságot egy nem létező tenantra néztük meg.
            // A `EntitlementChecker`-en: a SUB-ACCOUNT a PARENT csomagját használja (C1 · 76.).
            if ($pool->type === 'dedicated' && ! app(EntitlementChecker::class)->allows($workspace->workspace(), Feature::DedicatedIp)) {
                return ApiError::rejected(
                    'ENTITLEMENT_REQUIRED',
                    'A dedikált IP-pool használata a csomagod része kell legyen (dedicated_ip).'
                );
            }

            // Marketing IP nem vihet tranzakciós forgalmat és fordítva (spec 5.):
            // a reputáció-szeparáció az egész ESP-modell alapja.
            if (! $pool->servesChannel($payload->channel)) {
                return ApiError::badRequest('IP_POOL_CHANNEL_MISMATCH', sprintf(
                    'A(z) %s pool a(z) %s csatornára van fenntartva, nem a(z) %s-re.',
                    $pool->name,
                    (string) $pool->channel,
                    $payload->channel
                ));
            }

            $ipPoolId = $pool->getKey();
        }

        $result = $send([
            // A csatornát a payload-parser allowlistázza, a JOGOSULTSÁGOT az `api.channel`
            // middleware dönti el — a kontroller nem hoz biztonsági döntést.
            'channel' => $payload->channel,
            'from_email' => $payload->fromEmail,
            'from_name' => $payload->fromName,
            'subject' => $subject,
            'to' => $payload->recipients,
            // A sablon NEVE: az idempotencia-ujjlenyomat a kérést fedi, nem a feloldott törzset.
            'template' => $payload->template,
            'html' => $html,
            'text' => $text,
            'tags' => $payload->tags,
            'variables' => $payload->variables,
            'render_variables' => $payload->renderVariables,
            'headers' => $payload->headers,
            'options' => $options,
            'deliverytime' => $payload->deliverytime,
            // A teszt-kulcs testmode-ja NEM kapcsolható le a hívó oldaláról: egy CI-ban
            // hagyott kulcs különben valódi címzettekre küldene (Mailgun-viselkedés).
            'testmode' => $payload->testmode || $apiKey?->testmode === true,
            'ip_pool_id' => $ipPoolId,
            'idempotency_key' => (string) $request->attributes->get('idempotency_key'),
        ]);

        if (! $result->decision->allowed) {
            // Az idempotencia-kulcs újrahasználata a HÍVÓ hibája, nem a gate elutasítása:
            // 409, hogy a kliens tudja, a második levele NEM ment ki.
            if ($result->decision->reason === GateReason::IdempotencyKeyReused->value) {
                return ApiError::response(
                    GateReason::IdempotencyKeyReused->value,
                    'Ezt az Idempotency-Key-t már használtad MÁS kérés-tartalommal. '
                    .'Új levélhez új kulcs kell — különben a második levél csendben elveszne.',
                    409,
                    ['id' => $result->message->uuid]
                );
            }

            // A párhuzamos hívás döntése még nincs meg: NEM siker (nem 202), és NEM az
            // IDEMPOTENCY_KEY_REUSED (azt a kliensek „elküldve"-nek veszik). Újrapróbálható.
            if ($result->decision->reason === GateReason::InProgress->value) {
                return ApiError::response(
                    GateReason::InProgress->value,
                    'Az ugyanezzel az Idempotency-Key-jel indított kérés még feldolgozás alatt áll — próbáld újra pár másodperc múlva, ugyanazzal a kulccsal.',
                    409,
                    ['id' => $result->message->uuid]
                )->header('Retry-After', '1');
            }

            return ApiError::rejected(
                (string) $result->decision->reason,
                $this->explain($result->decision->reason),
                ['id' => $result->message->uuid]
            );
        }

        return response()->json([
            'id' => $result->message->uuid,
            'message' => 'Queued. Thank you.',
            'status' => $result->message->status,
        ], 202);
    }

    /**
     * A gate-kódok emberi magyarázata. A KÓD a szerződés, az üzenet segítség — ezért a
     * kód sosem változik, a szöveg finomítható (és fordítható).
     *
     * ## Miért a FORDÍTÓ-RÉTEGEN megy (C2 · 89. kritérium)
     *
     * Mert a hat nyelv kulcsai eddig HASZNÁLAT NÉLKÜL álltak: a
     * `LocaleCompletenessTest` őrizte a teljességüket, miközben a felület és az API
     * beégetett magyar szöveggel válaszolt. Egy fordítás, amit semmi nem hív, nem
     * fordítás — és az első valódi felhasználója (a felület) csendben elcsúszna tőle.
     *
     * A kulcs a gate-kódból származik (`SUPPRESSED` → `ventmail.gate.suppressed`), tehát
     * egy új kód nem néma hibát ad, hanem az általános `rejected` üzenetet — a
     * `Lang::has()` ellenőrzés miatt a nyers kulcs SOSEM jelenik meg a válaszban.
     */
    private function explain(?string $reason): string
    {
        $key = 'ventmail.gate.'.strtolower((string) $reason);

        return (string) (Lang::has($key) ? __($key) : __('ventmail.gate.rejected'));
    }
}
