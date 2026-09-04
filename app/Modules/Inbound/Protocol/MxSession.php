<?php

declare(strict_types=1);

namespace App\Modules\Inbound\Protocol;

use App\Modules\Inbound\Contracts\InboundRecipientResolver;
use App\Modules\Inbound\Contracts\InboundSink;
use App\Modules\Inbound\Data\InboundRecipient;
use App\Modules\Sending\Services\DomainNormalizer;
use App\Modules\Smtp\Contracts\SmtpConnection;
use App\Modules\Smtp\Data\ChannelSecurity;
use App\Modules\Smtp\Data\SmtpEnvelope;
use App\Modules\Smtp\Protocol\SessionState;
use App\Modules\Smtp\Protocol\SmtpReply;

/**
 * Az MX-oldal protokoll-állapotgépe (A4 · 30., 37. kritérium).
 *
 * I/O-MENTES, mint a submission-párja: sorokat kap, válaszokat ad. Amit nem csinál —
 * socket, TLS, fájl —, azt nem is lehet benne elrontani, és a teljes protokoll
 * viselkedése egységtesztből mérhető.
 *
 * MIÉRT KÜLÖN OSZTÁLY (ADR 0002): a két oldal invariánsa különböző, és mindkettőt KÜLÖN
 * kell bizonyítani.
 *
 *  - submission (587/465): „`MAIL FROM` nincs hitelesítés nélkül" — típus-szinten, a
 *    nem-nullable `SubmissionPrincipal`-lal;
 *  - MX (25): „nincs elfogadás olyan címzettre, aki nem a MI verifikált inbound-domainünk".
 *
 * Az `InboundSink::accept()` első paramétere ezért egy nem-üres, FELOLDOTT címzett-lista:
 * idegen domainre címzett levél átadása MEG SEM FOGALMAZHATÓ. Ez az MX-oldal nyílt-relay
 * zára — és a 25-ös porton `AUTH` egyáltalán nem létezik, tehát a „hitelesítettem, most
 * relayelj" út sem.
 *
 * NÉGY FÜGGETLEN ZÁR, hogy egyik elrontása se nyisson relayt:
 *  1. `AUTH` nincs a parancstáblában, és `EHLO` nem is hirdeti;
 *  2. a `RCPT TO` feloldatlan címre 550-nel elutasít (a levél a küldőnél marad);
 *  3. a `DATA` csak akkor indul, ha VAN feloldott címzett;
 *  4. a sink szignatúrája nem-üres feloldott listát követel — típus-szinten.
 */
final class MxSession implements SmtpConnection
{
    private SessionState $state = SessionState::Greeting;

    private SmtpEnvelope $envelope;

    /** @var list<InboundRecipient> */
    private array $resolved = [];

    private string $data = '';

    private int $dataBytes = 0;

    private bool $dataOverflow = false;

    private bool $dataDiscarding = false;

    private bool $close = false;

    private bool $startTls = false;

    private int $commands = 0;

    private int $transactions = 0;

    /** Hány címzettet utasítottunk el — a címfelderítés fékje. */
    private int $rejectedRecipients = 0;

    /** @var list<array{direction: string, line: string}> */
    private array $transcript = [];

    public function __construct(
        private readonly InboundRecipientResolver $resolver,
        private readonly InboundSink $sink,
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

    public function bufferedBytes(): int
    {
        return strlen($this->data);
    }

    public function shouldClose(): bool
    {
        return $this->close;
    }

    public function shouldStartTls(): bool
    {
        return $this->startTls;
    }

    /**
     * TLS-váltás után a session MINDENT elfelejt.
     *
     * A STARTTLS command-injection (CVE-2011-0411 családja) itt is él: a támadó a
     * titkosítatlan szakaszban parancsokat pipeline-oz, amiket a szerver a titkosított
     * munkamenet elején hajtana végre.
     */
    public function securedWith(ChannelSecurity $security): void
    {
        $this->security = $security;
        $this->startTls = false;
        $this->state = SessionState::Greeting;
        $this->envelope = new SmtpEnvelope;
        $this->resolved = [];
        $this->data = '';
        $this->dataBytes = 0;
        $this->dataOverflow = false;
        $this->dataDiscarding = false;
    }

    /** @return list<array{direction: string, line: string}> */
    public function transcript(): array
    {
        return $this->transcript;
    }

    /** @return list<SmtpReply> */
    public function feed(string $line): array
    {
        if ($this->close) {
            return [];
        }

        $line = rtrim($line, "\r\n");

        if ($this->state === SessionState::Data) {
            return $this->feedData($line);
        }

        $this->record('in', $line);

        if (strlen($line) > (int) config('inbound.smtp.max_command_bytes', 4096)) {
            return $this->closing(500, 'Line too long', '5.5.2');
        }

        $this->commands++;

        if ($this->commands > (int) config('inbound.smtp.max_commands', 100)) {
            return $this->closing(421, 'Too many commands', '4.7.0');
        }

        [$verb, $argument] = $this->parse($line);

        return match ($verb) {
            'EHLO' => $this->ehlo($argument),
            'HELO' => $this->helo($argument),
            'STARTTLS' => $this->starttls(),
            'MAIL' => $this->mail($argument),
            'RCPT' => $this->rcpt($argument),
            'DATA' => $this->beginData(),
            'RSET' => $this->reset(),
            'NOOP' => $this->reply(250, 'OK', '2.0.0'),
            'QUIT' => $this->closing(221, 'Bye', '2.0.0'),
            // A címfelderítés ellen: „nem erősítem meg, de megpróbálom".
            'VRFY' => $this->reply(252, 'Cannot VRFY user', '2.5.2'),
            'EXPN' => $this->reply(502, 'EXPN not supported', '5.5.1'),
            'HELP' => $this->reply(214, 'https://docs.ventmail.app/inbound'),
            // AUTH: ezen a porton NEM LÉTEZIK. Nem „nem engedélyezett" — nem is ismerjük.
            // Ez az első zár: a „hitelesítettem, most relayelj" út meg sem nyílik.
            'AUTH' => $this->reply(502, 'AUTH is not available on this port', '5.5.1'),
            default => $this->reply(500, 'Unrecognized command', '5.5.2'),
        };
    }

    /** @return list<SmtpReply> */
    private function ehlo(string $argument): array
    {
        if ($argument === '') {
            return $this->reply(501, 'EHLO requires a domain', '5.5.4');
        }

        $this->state = SessionState::Ready;
        $this->envelope->reset();
        $this->resolved = [];

        $extensions = [
            'PIPELINING',
            'SIZE '.(int) config('inbound.max_message_bytes'),
            '8BITMIME',
            'ENHANCEDSTATUSCODES',
            // RFC 6531: a nemzetközi címeket FOGADNI kell tudni — a küldő oldalán nem a mi
            // dolgunk eldönteni, milyen a helyi rész.
            'SMTPUTF8',
        ];

        // A STARTTLS-t csak titkosítatlan csatornán van értelme hirdetni. AUTH SEHOL —
        // ezen a porton nem létezik.
        if (! $this->security->encrypted) {
            $extensions[] = 'STARTTLS';
        }

        $replies = [new SmtpReply(250, $this->hostname.' greets '.$argument)];

        foreach ($extensions as $extension) {
            $replies[] = new SmtpReply(250, $extension);
        }

        foreach ($replies as $reply) {
            $this->record('out', $reply->toLine());
        }

        return $replies;
    }

    /** @return list<SmtpReply> */
    private function helo(string $argument): array
    {
        if ($argument === '') {
            return $this->reply(501, 'HELO requires a domain', '5.5.4');
        }

        $this->state = SessionState::Ready;
        $this->envelope->reset();
        $this->resolved = [];

        return $this->reply(250, $this->hostname);
    }

    /** @return list<SmtpReply> */
    private function starttls(): array
    {
        if ($this->security->encrypted) {
            return $this->reply(503, 'TLS already active', '5.5.1');
        }

        $this->startTls = true;

        return $this->reply(220, 'Ready to start TLS', '2.0.0');
    }

    /** @return list<SmtpReply> */
    private function mail(string $argument): array
    {
        if ($this->state === SessionState::Greeting) {
            return $this->reply(503, 'Send EHLO first', '5.5.1');
        }

        if ($this->state !== SessionState::Ready) {
            return $this->reply(503, 'Nested MAIL command', '5.5.1');
        }

        if (preg_match('/^FROM:\s*<([^>]*)>(.*)$/i', $argument, $match) !== 1) {
            return $this->reply(501, 'Syntax: MAIL FROM:<address>', '5.5.4');
        }

        // A NULL-SENDER (`MAIL FROM:<>`) ITT LEGITIM, sőt kötelező elfogadni: minden bounce
        // és minden kézbesítési értesítés így érkezik (RFC 5321 §4.5.5). A submission-oldal
        // épp fordítva dönt — ez a két oldal közti egyik lényegi különbség.
        $this->transactions++;

        if ($this->transactions > (int) config('inbound.smtp.max_transactions', 20)) {
            return $this->closing(421, 'Too many transactions', '4.7.0');
        }

        if (preg_match('/\bSIZE=(\d+)/i', $match[2], $sizeMatch) === 1
            && (int) $sizeMatch[1] > (int) config('inbound.max_message_bytes')) {
            return $this->reply(552, 'Message size exceeds fixed maximum message size', '5.3.4');
        }

        $this->envelope->reset();
        $this->resolved = [];
        $this->envelope->sender = $this->normalizeAddress($match[1]);
        $this->state = SessionState::Mail;

        return $this->reply(250, 'OK', '2.1.0');
    }

    /** @return list<SmtpReply> */
    private function rcpt(string $argument): array
    {
        if (! in_array($this->state, [SessionState::Mail, SessionState::Rcpt], true)) {
            return $this->reply(503, 'Send MAIL FROM first', '5.5.1');
        }

        if (preg_match('/^TO:\s*<([^>]*)>/i', $argument, $match) !== 1) {
            return $this->reply(501, 'Syntax: RCPT TO:<address>', '5.5.4');
        }

        if (count($this->resolved) >= (int) config('inbound.smtp.max_recipients', 100)) {
            return $this->reply(452, 'Too many recipients', '4.5.3');
        }

        $address = $this->normalizeAddress($match[1]);
        $recipient = $address === '' ? null : $this->resolver->resolve($address);

        // A MÁSODIK ZÁR — és az MX-oldal legfontosabb sora. Idegen domainre címzett levelet
        // NEM veszünk át: az elfogadott idegen levél pontosan az, amit a világ nyílt
        // relaynek nevez. Az elutasítás 550, tehát a levél a KÜLDŐNÉL marad — nem lesz
        // belőle nálunk ragadó bounce.
        if ($recipient === null) {
            $this->rejectedRecipients++;

            // Címfelderítés elleni fék: aki tucatnyi találgatással próbálja feltérképezni,
            // melyik cím létezik nálunk, annak a session véget ér.
            if ($this->rejectedRecipients >= (int) config('inbound.smtp.max_rejected_recipients', 10)) {
                return $this->closing(421, 'Too many invalid recipients', '4.7.0');
            }

            return $this->reply(550, 'Relay access denied', '5.7.1');
        }

        $this->envelope->recipients[] = $recipient->address;
        $this->resolved[] = $recipient;
        $this->state = SessionState::Rcpt;

        return $this->reply(250, 'OK', '2.1.5');
    }

    /** @return list<SmtpReply> */
    private function beginData(): array
    {
        // A HARMADIK ZÁR: `DATA` csak feloldott címzettel indul. A `SessionState::Rcpt`
        // állapotba kizárólag sikeres feloldás után jutunk.
        if ($this->state !== SessionState::Rcpt || $this->resolved === []) {
            return $this->reply(503, 'Send RCPT TO first', '5.5.1');
        }

        $this->state = SessionState::Data;
        $this->data = '';
        $this->dataBytes = 0;
        $this->dataOverflow = false;
        $this->dataDiscarding = false;

        return $this->reply(354, 'End data with <CR><LF>.<CR><LF>');
    }

    /** @return list<SmtpReply> */
    private function feedData(string $line): array
    {
        if ($line === '.') {
            return $this->finishData();
        }

        // Transzparencia (RFC 5321 §4.5.2): a sor eleji dupla pont egyet jelent.
        if (str_starts_with($line, '..')) {
            $line = substr($line, 1);
        }

        $this->dataBytes += strlen($line) + 2;

        if ($this->dataBytes > (int) config('inbound.max_message_bytes')) {
            // A TÚLMÉRETES levelet ELDOBJUK, de a lezáró pontig OLVASSUK: a kapcsolat
            // félbehagyása a küldőnél végtelen újrapróbálást szülne.
            $this->dataOverflow = true;
            $this->dataDiscarding = true;
            $this->data = '';

            return [];
        }

        if ($this->dataDiscarding) {
            return [];
        }

        $this->data .= $line."\r\n";

        return [];
    }

    /** @return list<SmtpReply> */
    private function finishData(): array
    {
        $raw = $this->data;
        $overflow = $this->dataOverflow;

        $this->data = '';
        $this->dataBytes = 0;
        $this->dataOverflow = false;
        $this->dataDiscarding = false;
        $this->state = SessionState::Ready;

        if ($overflow) {
            return $this->reply(552, 'Message size exceeds fixed maximum message size', '5.3.4');
        }

        if ($this->resolved === []) {
            // Ide nem jutunk el (a `beginData` zár), de ha egy jövőbeli refaktor mégis
            // megnyitná az utat, itt HANGOSAN elutasítunk — nem csendben átveszünk.
            return $this->reply(554, 'No valid recipients', '5.5.1');
        }

        $envelope = clone $this->envelope;
        $recipients = $this->resolved;

        $this->envelope->reset();
        $this->resolved = [];

        $reply = $this->sink->accept($recipients, $envelope, $raw);

        $this->record('out', $reply->toLine());

        return [$reply];
    }

    /** @return list<SmtpReply> */
    private function reset(): array
    {
        $this->envelope->reset();
        $this->resolved = [];
        $this->state = $this->state === SessionState::Greeting ? SessionState::Greeting : SessionState::Ready;

        return $this->reply(250, 'OK', '2.0.0');
    }

    /** @return list<SmtpReply> */
    private function closing(int $code, string $text, ?string $enhanced = null): array
    {
        $this->close = true;
        $this->state = SessionState::Closed;

        return $this->reply($code, $text, $enhanced);
    }

    /** @return list<SmtpReply> */
    private function reply(int $code, string $text, ?string $enhanced = null): array
    {
        $reply = new SmtpReply($code, $text, $enhanced);

        $this->record('out', $reply->toLine());

        return [$reply];
    }

    /** @return array{0: string, 1: string} */
    private function parse(string $line): array
    {
        $trimmed = trim($line);
        $space = strpos($trimmed, ' ');

        return $space === false
            ? [strtoupper($trimmed), '']
            : [strtoupper(substr($trimmed, 0, $space)), trim(substr($trimmed, $space + 1))];
    }

    /**
     * A DOMAIN kanonikus (punycode) alakra megy, a HELYI RÉSZ változatlan.
     *
     * A helyi részt kizárólag a fogadó értelmezheti (RFC 5321 §2.4) — mi vagyunk a fogadó,
     * de a route-illesztés az ügyfél szabálya szerint megy, tehát nem alakítjuk át. A
     * domaint viszont igen: enélkül a `példa.hu` és a `xn--plda-bpa.hu` két külön
     * címzettnek látszana, és az egyiken átcsúszna, ami a másikon tilos.
     */
    private function normalizeAddress(string $address): string
    {
        return DomainNormalizer::email(trim($address));
    }

    private function record(string $direction, string $line): void
    {
        if (count($this->transcript) < 200) {
            $this->transcript[] = ['direction' => $direction, 'line' => $line];
        }
    }
}
