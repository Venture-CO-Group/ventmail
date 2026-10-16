<?php

declare(strict_types=1);

namespace App\Modules\Suppression\Http\Controllers;

use App\Modules\Consent\Services\ConsentLedgerReporter;
use App\Modules\Events\Models\MessageEvent;
use App\Modules\Sending\Models\Message;
use App\Modules\Sending\Services\DomainNormalizer;
use App\Modules\Suppression\Services\SuppressionStore;
use App\Modules\Suppression\Services\UnsubscribeTokens;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * ONE-CLICK LEIRATKOZÁS (RFC 8058, 47. kritérium).
 *
 * A `POST` a szabvány szerinti út: a levélkliens (Gmail, Yahoo, Apple Mail) EGY KATTINTÁSSAL
 * hívja meg, megerősítő oldal nélkül — ez a végpont ír, és NEM kérdez vissza. Ha itt
 * megerősítést kérnénk, a one-click nem lenne one-click.
 *
 * A `GET` viszont NEM ÍR, csak egy egygombos oldalt mutat. Ez nem óvatoskodás: a levélben lévő
 * linkeket a védelmi átjárók VÉGIGLÁTOGATJÁK — az Outlook SafeLinks, a vírusszűrő proxyk és a
 * kliens-előtöltés mind meghívja őket, mielőtt a címzett bármit tenne. Írós GET-tel ezek
 * csendben leiratkoztatnák azt, aki soha nem kattintott, és a hibát senki nem venné észre: a
 * küldő csak azt látná, hogy „sokan leiratkoztak". Az RFC 8058 pontosan ezért ír elő POST-ot.
 *
 * A gomb egy kattintás — a megerősítés ára ennyi, és csak azon az úton, amit a szabvány nem
 * véd.
 *
 * A HITELESÍTÉS a token: titkosított, tehát hamisíthatatlan, és a tenant-kontextus is belőle
 * jön. Ugyanaz a minta, mint a nyomkövetésnél — és ugyanaz a zár: hoszt-kötés nincs, mert a
 * leiratkozás bármely úton legitim, de a token nélkül a végpont nem csinál semmit.
 */
final class UnsubscribeController
{
    public function __construct(
        private readonly UnsubscribeTokens $tokens,
        private readonly SuppressionStore $suppressions,
    ) {}

    /** RFC 8058: a levélkliens POST-ol, `List-Unsubscribe=One-Click` törzzsel. */
    public function post(Request $request, string $token, WorkspaceContext $context): Response
    {
        $this->suppress($token, $context);

        // A LEVÉLKLIENSNEK a 200 a lényeg, a tartalom közömbös. A megerősítő oldalról érkező
        // EMBERNEK viszont mutatni kell valamit — neki egy csupasz „OK" azt üzenné, hogy
        // valami félresikerült, és a következő lépése a spam-gomb lenne.
        if (str_contains((string) $request->header('Accept'), 'text/html')) {
            return response(view('suppression.unsubscribed')->render(), 200);
        }

        return response('OK', 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /**
     * Emberi kattintás a levélben — MEGERŐSÍTŐ OLDAL, írás nélkül.
     *
     * MINDIG ugyanaz az oldal, akkor is, ha a token érvénytelen: a különbség orákulum lenne
     * arra, hogy egy cím szerepel-e a rendszerben.
     */
    public function get(string $token): Response
    {
        return response(view('suppression.unsubscribe', ['token' => $token])->render(), 200);
    }

    /**
     * Az üzenet létezik ebben a workspace-ben, ÉS a cím a címzettje volt?
     *
     * @param  array{workspaceId: int, messageUuid: string, recipient: string}  $claim
     */
    private function claimIsCoherent(array $claim): bool
    {
        // A `messageUuid` a token BELSEJÉBŐL jön, tehát mi írtuk alá — de a formátumát
        // ellenőrizni kell, mert az `uuid` oszlop típusos: egy nem-UUID érték a Postgresen
        // `invalid input syntax for type uuid` hibát ad, abból pedig 500 lesz. Az 500 itt
        // KÉTSZERESEN kár: a végpont orákulummá válik (a hibás token más választ ad, mint a
        // jó), és a levélkliens újrapróbálásra alkalmas hibának hiszi.
        if (! Str::isUuid($claim['messageUuid'])) {
            return false;
        }

        $message = Message::query()
            ->where('uuid', $claim['messageUuid'])
            ->first(['recipients']);

        if ($message === null) {
            return false;
        }

        // A CÍMZETTEK a küldés normalizált alakjában vannak; a claim ugyanabból származik.
        $recipients = array_map(
            static fn ($recipient) => DomainNormalizer::listKey((string) $recipient),
            (array) $message->recipients,
        );

        return in_array(DomainNormalizer::listKey($claim['recipient']), $recipients, true);
    }

    /**
     * `unsubscribed` ESEMÉNY az üzenetre — a webhook-előfizető (pl. a Vemoa, ami ebből állítja
     * le a WhatsApp-marketinget is) enélkül nem tudna a leiratkozásról. Mailgun-paritás.
     *
     * Üzenet + címzett párosonként EGYSZER: a Gmail a one-click POST-ot újrapróbálja, és
     * minden ismétlés egy újabb kimenő webhook lenne. Az esemény a hívó `v:` változóit viszi
     * (korreláció), a kattintás körülményeit (IP, UA) nem.
     *
     * @param  array{workspaceId: int, messageUuid: string, recipient: string}  $claim
     */
    private function recordEvent(array $claim): void
    {
        $recipient = DomainNormalizer::listKey((string) $claim['recipient']);

        $message = Message::query()->where('uuid', $claim['messageUuid'])->first();

        if ($message === null) {
            return;
        }

        $already = MessageEvent::query()
            ->where('message_uuid', $message->uuid)
            ->where('type', 'unsubscribed')
            ->where('recipient', $recipient)
            ->exists();

        if ($already) {
            return;
        }

        MessageEvent::create([
            'message_uuid' => $message->uuid,
            'type' => 'unsubscribed',
            'recipient' => $recipient,
            'channel' => $message->channel,
            'tags' => $message->tags,
            'variables' => $message->echoedVariables(),
            'payload' => [],
        ]);
    }

    private function suppress(string $token, WorkspaceContext $context): bool
    {
        $claim = $this->tokens->parse($token);

        if ($claim === null) {
            return false;
        }

        $workspace = Workspace::query()->whereKey($claim['workspaceId'])->first();

        if ($workspace === null) {
            return false;
        }

        $previous = $context->hasWorkspace() ? $context->workspace() : null;

        $context->set($workspace);

        try {
            // MÉLYSÉGI VÉDELEM: az igény legyen ÖSSZEFÜGGŐ.
            //
            // A token titkosított, tehát nem hamisítható — de bemutatóra szól, és a levélben
            // megy ki: elkapható (proxy-napló, továbbított levél). Ha csak a token belsejét
            // néznénk, egy elkapott token akkor is beváltható lenne, ha az üzenet, amire
            // hivatkozik, nem is ehhez a workspace-hez tartozik, vagy a címzett soha nem volt
            // a címzettje. Ez a lekérdezés nem drága, és nem regexre épül — szemben a
            // fejlécszintű felismeréssel, ami ebben a packetben négyszer nyílt ki.
            //
            // KÖVETKEZMÉNY, amit ki kell mondani: a GDPR-törlés (`logs:redact-subject`)
            // kiveszi a címet a `messages.recipients`-ből, tehát a törölt érintett RÉGI
            // levelében lévő leiratkozó link ettől kezdve nem ír semmit. Az érintettnél ez
            // helyes kimenet (a címéről már nincs adatunk, és marketinget sem kap), de azt
            // jelenti, hogy a leiratkozás működése a TÖRLÉSI ÁLLAPOTHOZ kötött — nem
            // véletlen, hanem következmény.
            if (! $this->claimIsCoherent($claim)) {
                return false;
            }

            // `applies_to = marketing`: a leiratkozás a MARKETINGRE szól. A tranzakciós
            // levél (számla, jelszó-visszaállítás) ettől függetlenül mehet — golden rule 4.
            //
            // A STORE ír, nem a controller: a cím normalizálása, a meglévő sor monoton
            // szigorítása és az ismételt hívás (a Gmail újrapróbálja a one-click POST-ot)
            // ott van egy helyen, tesztelve.
            // A MELYIK LEVÉLBŐL is rögzül: a leiratkozás a consent VISSZAVONÁSA, és
            // ugyanolyan bizonyítékot érdemel, mint a megadása. A token hordozza az üzenet
            // uuid-ját; ha csak `one_click`-et írnánk, egy későbbi panasznál nem tudnánk
            // megmutatni, melyik levélből érkezett.
            $this->suppressions->recordUnsubscribe(
                (string) $claim['recipient'],
                detail: 'message:'.$claim['messageUuid'],
            );

            $this->recordEvent($claim);

            // FO3: a leiratkozás a consent VISSZAVONÁSA — a ventCookie főkönyvbe is bizonyíték.
            // A helyi consent-sort nem érinti (a suppression a hivatalos tiltás), és a főkönyv
            // hibája a leiratkozást sosem blokkolja (a reporter elnyeli).
            app(ConsentLedgerReporter::class)->withdrawnByEmail((string) $claim['recipient'], 'message:'.$claim['messageUuid']);

            return true;
        } finally {
            $previous === null ? $context->forget() : $context->set($previous);
        }
    }
}
