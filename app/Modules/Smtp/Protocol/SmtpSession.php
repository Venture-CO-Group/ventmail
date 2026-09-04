<?php

declare(strict_types=1);

namespace App\Modules\Smtp\Protocol;

use App\Modules\Sending\Services\DomainNormalizer;
use App\Modules\Smtp\Contracts\SmtpAuthenticator;
use App\Modules\Smtp\Contracts\SmtpConnection;
use App\Modules\Smtp\Contracts\SubmissionSink;
use App\Modules\Smtp\Data\ChannelSecurity;
use App\Modules\Smtp\Data\SmtpEnvelope;
use App\Modules\Smtp\Data\SubmissionPrincipal;

/**
 * Az SMTP submission ÁLLAPOTGÉPE — I/O NÉLKÜL (ADR 0001, D2).
 *
 * Nincs benne socket, adatbázis, facade és `now()` sem: sorokat kap, válaszokat ad. Ezért
 * a 11–15. kritérium determinisztikusan, hálózat nélkül bizonyítható — a nyílt relay
 * tilalmának bizonyítéka nem függhet attól, hogy a CI-ban éppen szabad-e egy port.
 *
 * A BIZALMI HATÁR (ADR D3) négy zárból áll; ez az osztály kettőt tart:
 *  1. protokoll-szint: `MAIL FROM` principal nélkül `530`, `RCPT`/`DATA` `503`;
 *  2. típus-szint: a `SubmissionSink::accept()` első paramétere nem-nullable
 *     `SubmissionPrincipal` — hitelesítetlen sessionből a hívás nem megfogalmazható.
 * A másik kettő (kontextus-szintű fail-closed a `PreSendGate`-ben, és az arch-teszt, hogy
 * ez a modul nem ismeri a `SendingDriver`-t) a modulon kívül él.
 */
final class SmtpSession implements SmtpConnection
{
    private SessionState $state = SessionState::Greeting;

    private ?SubmissionPrincipal $principal = null;

    private SmtpEnvelope $envelope;

    private string $data = '';

    private int $dataBytes = 0;

    private bool $dataOverflow = false;

    /** Túlméretes levél: a hátralévő sorokat ELDOBJUK, de a `.`-ig OLVASSUK. */
    private bool $dataDiscarding = false;

    private bool $close = false;

    private bool $startTls = false;

    private int $commands = 0;

    private int $transactions = 0;

    private int $authFailures = 0;

    private int $longLines = 0;

    /** A `AUTH LOGIN` kétlépcsős: itt tartjuk, mire várunk. */
    private ?string $awaiting = null;

    private ?string $loginUser = null;

    /** @var list<string> */
    private array $transcript = [];

    public function __construct(
        private readonly SmtpAuthenticator $authenticator,
        private readonly SubmissionSink $sink,
        private ChannelSecurity $security,
        private readonly string $hostname,
    ) {
        $this->envelope = new SmtpEnvelope;
    }

    public function greeting(): SmtpReply
    {
        return new SmtpReply(220, $this->hostname.' ventMail ESMTP');
    }

    public function state(): SessionState
    {
        return $this->state;
    }

    /** Mennyi levéltörzset tart épp a memóriában ez a session (globális plafonhoz). */
    public function bufferedBytes(): int
    {
        return strlen($this->data);
    }

    public function principal(): ?SubmissionPrincipal
    {
        return $this->principal;
    }

    public function shouldClose(): bool
    {
        return $this->close;
    }

    /** A transzportnak: fel kell-e emelni a kapcsolatot TLS-re. */
    public function shouldStartTls(): bool
    {
        return $this->startTls;
    }

    /**
     * A TLS-váltás UTÁN a session MINDENT elfelejt.
     *
     * Enélkül a klasszikus STARTTLS command-injection (CVE-2011-0411 családja) él: a
     * támadó a titkosítatlan szakaszban parancsokat pipeline-oz, amiket a szerver a
     * titkosított munkamenet elején hajtana végre — a kliens nevében.
     */
    public function securedWith(ChannelSecurity $security): void
    {
        $this->security = $security;
        $this->startTls = false;
        $this->state = SessionState::Greeting;
        $this->principal = null;
        $this->awaiting = null;
        $this->loginUser = null;
        $this->envelope = new SmtpEnvelope;
        $this->data = '';
        $this->dataBytes = 0;
        $this->dataOverflow = false;
        $this->dataDiscarding = false;
    }

    /** @return list<string> a párbeszéd naplója — az AUTH sorok REDAKÁLVA */
    public function transcript(): array
    {
        return $this->transcript;
    }

    /**
     * Egyetlen bemenő sor feldolgozása.
     *
     * @return list<SmtpReply> pipelining miatt egy sor több választ is adhat
     */
    public function feed(string $line): array
    {
        if ($this->close) {
            return [];
        }

        $line = rtrim($line, "\r\n");

        if ($this->state === SessionState::Data) {
            return $this->feedData($line);
        }

        $this->record($line);

        if (strlen($line) > $this->limit($this->awaiting === null ? 'max_command_bytes' : 'max_auth_bytes')) {
            $this->longLines++;

            if ($this->longLines >= 3) {
                return $this->closing(500, 'Too many oversized lines', '5.5.2');
            }

            return [new SmtpReply(500, 'Line too long', '5.5.2')];
        }

        if ($this->awaiting !== null) {
            return $this->continueAuth($line);
        }

        $this->commands++;

        if ($this->commands > $this->limit('max_commands')) {
            return $this->closing(421, 'Too many commands', '4.7.0');
        }

        if ($this->principal === null && $this->commands > $this->limit('max_commands_before_auth')) {
            return $this->closing(421, 'Too many commands before authentication', '4.7.0');
        }

        [$verb, $argument] = $this->parse($line);

        return match ($verb) {
            'EHLO' => $this->ehlo($argument),
            'HELO' => $this->helo($argument),
            'STARTTLS' => $this->starttls(),
            'AUTH' => $this->authenticate($argument),
            'MAIL' => $this->mail($argument),
            'RCPT' => $this->rcpt($argument),
            'DATA' => $this->beginData(),
            'RSET' => $this->reset(),
            'NOOP' => [new SmtpReply(250, 'OK', '2.0.0')],
            'QUIT' => $this->closing(221, 'Bye', '2.0.0'),
            // Címfelderítés ellen: a 252 azt jelenti, „nem erősítem meg, de megpróbálom".
            'VRFY' => [new SmtpReply(252, 'Cannot VRFY user', '2.5.2')],
            'EXPN' => [new SmtpReply(502, 'EXPN not supported', '5.5.1')],
            'HELP' => [new SmtpReply(214, 'https://docs.ventmail.app/smtp')],
            default => [new SmtpReply(500, 'Unrecognized command', '5.5.2')],
        };
    }

    /** @return list<SmtpReply> */
    private function ehlo(string $argument): array
    {
        if ($argument === '') {
            return [new SmtpReply(501, 'EHLO requires a domain', '5.5.4')];
        }

        $this->state = SessionState::Ready;
        $this->envelope->reset();

        $extensions = [
            'PIPELINING',
            'SIZE '.$this->limit('max_message_bytes'),
            '8BITMIME',
            'ENHANCEDSTATUSCODES',
            // Nemzetközi címek (RFC 6531). A helyi részt SOHA nem alakítjuk át — azt
            // kizárólag a fogadó értelmezheti (RFC 5321 §2.4); a DOMAINT viszont kanonikus
            // (punycode) alakra hozzuk, különben a `példa.hu` és a `xn--plda-bpa.hu`
            // két külön domainnek látszana, és a hitelesített domain kapuja megkerülhető.
            'SMTPUTF8',
        ];

        // Titkosítatlan csatornán az AUTH-ot NEM hirdetjük (és megkísérelve is 538) —
        // a jelszó különben olvashatóan menne át a hálózaton (golden rule 3).
        if (! $this->security->encrypted) {
            $extensions[] = 'STARTTLS';
        } else {
            $extensions[] = 'AUTH PLAIN LOGIN';
        }

        $replies = [new SmtpReply(250, $this->hostname.' greets '.$argument)];

        foreach ($extensions as $extension) {
            $replies[] = new SmtpReply(250, $extension);
        }

        // Egyetlen logikai válasz több sorban — a hívónak egy kód kell.
        return [new SmtpReply(250, implode("\n", array_map(fn (SmtpReply $r) => $r->text, $replies)))];
    }

    /** @return list<SmtpReply> */
    private function helo(string $argument): array
    {
        if ($argument === '') {
            return [new SmtpReply(501, 'HELO requires a domain', '5.5.4')];
        }

        $this->state = SessionState::Ready;

        return [new SmtpReply(250, $this->hostname)];
    }

    /** @return list<SmtpReply> */
    private function starttls(): array
    {
        if ($this->security->encrypted) {
            return [new SmtpReply(503, 'TLS already active', '5.5.1')];
        }

        $this->startTls = true;

        return [new SmtpReply(220, 'Ready to start TLS', '2.0.0')];
    }

    /** @return list<SmtpReply> */
    private function authenticate(string $argument): array
    {
        if (! $this->security->encrypted) {
            // A hirdetés elhagyása önmagában nem védelem: a kliensek egy része hirdetés
            // nélkül is próbálkozik, és a jelszó ilyenkor titkosítatlanul menne át.
            return [new SmtpReply(538, 'Encryption required for requested authentication mechanism', '5.7.11')];
        }

        if ($this->principal !== null) {
            return [new SmtpReply(503, 'Already authenticated', '5.5.1')];
        }

        [$mechanism, $initial] = $this->parse($argument);

        return match (strtoupper($mechanism)) {
            'PLAIN' => $initial === ''
                ? $this->await('PLAIN', new SmtpReply(334, ''))
                : $this->finishPlain($initial),
            'LOGIN' => $this->await('LOGIN_USER', new SmtpReply(334, base64_encode('Username:'))),
            default => [new SmtpReply(504, 'Unsupported authentication mechanism', '5.5.4')],
        };
    }

    /** @return list<SmtpReply> */
    private function await(string $what, SmtpReply $reply): array
    {
        $this->awaiting = $what;

        return [$reply];
    }

    /** @return list<SmtpReply> */
    private function continueAuth(string $line): array
    {
        $awaiting = $this->awaiting;
        $this->awaiting = null;

        if ($line === '*') {
            return [new SmtpReply(501, 'Authentication aborted', '5.7.8')];
        }

        return match ($awaiting) {
            'PLAIN' => $this->finishPlain($line),
            'LOGIN_USER' => $this->awaitLoginPassword($line),
            'LOGIN_PASSWORD' => $this->attempt((string) $this->loginUser, (string) base64_decode($line, true)),
            default => [new SmtpReply(503, 'Unexpected continuation', '5.5.1')],
        };
    }

    /** @return list<SmtpReply> */
    private function awaitLoginPassword(string $line): array
    {
        $this->loginUser = (string) base64_decode($line, true);

        return $this->await('LOGIN_PASSWORD', new SmtpReply(334, base64_encode('Password:')));
    }

    /** @return list<SmtpReply> */
    private function finishPlain(string $encoded): array
    {
        $decoded = base64_decode($encoded, true);

        if ($decoded === false) {
            return $this->authFailed('Invalid base64');
        }

        // A PLAIN alakja: authzid \0 authcid \0 passwd
        $parts = explode("\0", $decoded);

        if (count($parts) !== 3) {
            return $this->authFailed('Malformed credentials');
        }

        return $this->attempt($parts[1], $parts[2]);
    }

    /** @return list<SmtpReply> */
    private function attempt(string $username, string $password): array
    {
        $principal = $this->authenticator->authenticate($username, $password, $this->security);

        if ($principal === null) {
            return $this->authFailed('Authentication credentials invalid');
        }

        $this->principal = $principal;
        $this->authFailures = 0;

        return [new SmtpReply(235, 'Authentication successful', '2.7.0')];
    }

    /** @return list<SmtpReply> */
    private function authFailed(string $reason): array
    {
        $this->authFailures++;

        if ($this->authFailures >= $this->limit('max_auth_failures')) {
            return $this->closing(421, 'Too many authentication failures', '4.7.0');
        }

        // EGYETLEN üzenet minden hibára: a különböző szövegek orákulumot adnának arra,
        // hogy a felhasználónév létezik-e.
        return [new SmtpReply(535, 'Authentication credentials invalid', '5.7.8')];
    }

    /** @return list<SmtpReply> */
    private function mail(string $argument): array
    {
        // A LEGFONTOSABB ág: hitelesítés nélkül itt áll meg a nyílt relay.
        if ($this->principal === null) {
            return [new SmtpReply(530, 'Authentication required', '5.7.0')];
        }

        if ($this->state === SessionState::Greeting) {
            return [new SmtpReply(503, 'Send EHLO first', '5.5.1')];
        }

        if ($this->state !== SessionState::Ready) {
            return [new SmtpReply(503, 'Nested MAIL command', '5.5.1')];
        }

        if (preg_match('/^FROM:\s*<([^>]*)>(.*)$/i', $argument, $match) !== 1) {
            return [new SmtpReply(501, 'Syntax: MAIL FROM:<address>', '5.5.4')];
        }

        // A NULL-SENDER (`MAIL FROM:<>`) a bounce-ok visszaútja — submission-porton nem
        // legitim, és a `DomainVerifiedGate` sem tudna mit kezdeni vele.
        if (trim($match[1]) === '') {
            return [new SmtpReply(501, 'Null sender is not allowed on submission', '5.5.4')];
        }

        // A számláló CSAK érvényes tranzakcióra nő: különben ötven elgépelt `MAIL` sor
        // bontaná a sessiont.
        $this->transactions++;

        if ($this->transactions > $this->limit('max_transactions')) {
            return $this->closing(421, 'Too many transactions', '4.7.0');
        }

        $size = null;

        if (preg_match('/\bSIZE=(\d+)/i', $match[2], $sizeMatch) === 1) {
            $size = (int) $sizeMatch[1];

            // A méret-túllépést AZONNAL jelezzük: a kliens ne töltsön fel 25 MB-ot azért,
            // hogy a végén utasítsuk el.
            if ($size > $this->limit('max_message_bytes')) {
                return [new SmtpReply(552, 'Message size exceeds fixed maximum message size', '5.3.4')];
            }
        }

        $this->envelope->reset();
        $this->envelope->sender = $this->normalizeAddress($match[1]);
        $this->envelope->declaredSize = $size;
        $this->state = SessionState::Mail;

        return [new SmtpReply(250, 'OK', '2.1.0')];
    }

    /** @return list<SmtpReply> */
    private function rcpt(string $argument): array
    {
        if ($this->principal === null) {
            return [new SmtpReply(503, 'Authentication required', '5.5.1')];
        }

        if (! in_array($this->state, [SessionState::Mail, SessionState::Rcpt], true)) {
            return [new SmtpReply(503, 'Send MAIL FROM first', '5.5.1')];
        }

        if (preg_match('/^TO:\s*<([^>]*)>/i', $argument, $match) !== 1) {
            return [new SmtpReply(501, 'Syntax: RCPT TO:<address>', '5.5.4')];
        }

        if (count($this->envelope->recipients) >= (int) config('sending.max_recipients')) {
            return [new SmtpReply(452, 'Too many recipients', '4.5.3')];
        }

        $this->envelope->recipients[] = $this->normalizeAddress($match[1]);
        $this->state = SessionState::Rcpt;

        return [new SmtpReply(250, 'OK', '2.1.5')];
    }

    /** @return list<SmtpReply> */
    private function beginData(): array
    {
        if ($this->principal === null) {
            return [new SmtpReply(503, 'Authentication required', '5.5.1')];
        }

        if ($this->state !== SessionState::Rcpt) {
            return [new SmtpReply(503, 'Send RCPT TO first', '5.5.1')];
        }

        $this->state = SessionState::Data;
        $this->data = '';
        $this->dataBytes = 0;
        $this->dataOverflow = false;
        $this->dataDiscarding = false;

        return [new SmtpReply(354, 'End data with <CR><LF>.<CR><LF>')];
    }

    /** @return list<SmtpReply> */
    private function feedData(string $line): array
    {
        if ($line === '.') {
            return $this->endData();
        }

        // Transparency (RFC 5321 §4.5.2): a ponttal kezdődő sorról a kliens egy pontot tesz.
        if (str_starts_with($line, '..')) {
            $line = substr($line, 1);
        }

        if (strlen($line) > $this->limit('max_data_line_bytes')) {
            $this->dataOverflow = true;
        }

        $this->dataBytes += strlen($line) + 2;

        if ($this->dataBytes > $this->limit('max_message_bytes')) {
            // A tranzakciót eldobjuk, de a `.`-ig TOVÁBB OLVASUNK.
            //
            // Korábban itt azonnal `Ready`-be léptünk vissza — és onnantól a levéltörzs
            // SORAI PARANCSKÉNT futottak, egy MÁR HITELESÍTETT sessionben. Aki
            // felhasználói szövegből épít MIME-ot (helpdesk, űrlap), az a méret-limit
            // átlépése után tetszőleges TOVÁBBI levelet küldethetett a tenant nevében:
            //
            //   <törzs, ami átlépi a limitet>   -> 552, vissza Ready-be
            //   MAIL FROM:<...>                 -> 250   <- a „törzs" innen PARANCS
            //   RCPT TO:<aldozat@...> / DATA    -> becsempészett második levél
            //
            // A törzset NEM tartjuk meg (az lenne a memória-kimerítés), de a
            // tranzakció-határ csak a lezáró ponttal ér véget.
            $this->data = '';
            $this->dataDiscarding = true;

            return [];
        }

        if ($this->dataDiscarding) {
            return [];
        }

        $this->data .= $line."\r\n";

        return [];
    }

    /** @return list<SmtpReply> */
    private function endData(): array
    {
        $raw = $this->data;
        $principal = $this->principal;
        $discarding = $this->dataDiscarding;

        $this->data = '';
        $this->state = SessionState::Ready;
        $this->dataDiscarding = false;
        $envelope = clone $this->envelope;
        $this->envelope->reset();

        // A túlméretes levél elutasítása CSAK ITT jön meg: a tranzakció addig tart, amíg a
        // kliens le nem zárja. Enélkül a törzs maradéka parancsként futott volna.
        if ($discarding) {
            return [new SmtpReply(552, 'Message size exceeds fixed maximum message size', '5.3.4')];
        }

        if ($this->dataOverflow) {
            return [new SmtpReply(500, 'Line too long', '5.5.2')];
        }

        // A típus garantálja, hogy ide hitelesítetlenül nem juthatunk — de a fail-closed
        // ellenőrzés akkor is itt van, mert ez az utolsó pont a küldés előtt.
        if ($principal === null) {
            return [new SmtpReply(530, 'Authentication required', '5.7.0')];
        }

        return [$this->sink->accept($principal, $envelope, $raw)];
    }

    /** @return list<SmtpReply> */
    private function reset(): array
    {
        $this->envelope->reset();
        $this->state = $this->state === SessionState::Greeting ? SessionState::Greeting : SessionState::Ready;

        return [new SmtpReply(250, 'OK', '2.0.0')];
    }

    /** @return list<SmtpReply> */
    private function closing(int $code, string $text, string $enhanced): array
    {
        $this->close = true;
        $this->state = SessionState::Closed;

        return [new SmtpReply($code, $text, $enhanced)];
    }

    /**
     * Boríték-cím normalizálása.
     *
     * A HELYI RÉSZ érintetlen marad (kis/nagybetű is): azt csak a fogadó értelmezheti, és
     * az `SMTPUTF8` óta unicode is lehet. A DOMAIN viszont kanonikus punycode alakra megy,
     * mert azt MI hasonlítjuk össze (domain-kapu, hitelesítő-kötés, throttle).
     */
    private function normalizeAddress(string $address): string
    {
        return DomainNormalizer::email(trim($address));
    }

    /** @return array{0: string, 1: string} */
    private function parse(string $line): array
    {
        $trimmed = trim($line);
        $position = strpos($trimmed, ' ');

        return $position === false
            ? [strtoupper($trimmed), '']
            : [strtoupper(substr($trimmed, 0, $position)), trim(substr($trimmed, $position + 1))];
    }

    private function record(string $line): void
    {
        // A base64-elt AUTH sor MAGA a jelszó: a naplóba (és az A3 trace-be) sosem kerül.
        $this->transcript[] = $this->awaiting !== null || str_starts_with(strtoupper($line), 'AUTH ')
            ? 'AUTH PLAIN <redacted>'
            : $line;
    }

    private function limit(string $name): int
    {
        return (int) config('smtp.limits.'.$name);
    }
}
