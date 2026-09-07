<?php

declare(strict_types=1);

namespace App\Modules\Validation\Services;

use App\Modules\Domains\Contracts\DnsResolver;
use App\Modules\Sending\Services\DomainNormalizer;
use App\Modules\Validation\Contracts\MailboxProbe;
use App\Modules\Validation\Data\ProbeResult;
use App\Modules\Validation\Data\ValidationVerdict;

/**
 * A NYOLC ELLENŐRZÉS láncba fűzve (39. kritérium).
 *
 * A sorrend nem esztétika, hanem költség: a szintaxis ingyen van, az MX egy DNS-lekérdezés, a
 * szonda egy IDEGEN szerverhez intézett SMTP-párbeszéd. Ami olcsóbban eldönthető, azt előbb
 * döntjük el — és amint biztosan tudjuk, hogy a cím rossz, MEGÁLLUNK. Egy szintaktikailag
 * hibás címre szondát küldeni nemcsak felesleges: a fogadó felé is minket minősít.
 *
 * A PONTSZÁM és a MINŐSÍTÉS külön dolog. A pontszám folytonos (a gate küszöbbel dönt), a
 * minősítés az a négy szó, amit az ügyfél lát. Egy puszta 0.42-ből az integrátor nem tudná,
 * mit kezdjen vele.
 *
 * Az `unknown` NEM szégyen. A catch-all szerver és a greylisting mögött nincs igazság, amit
 * ki lehetne deríteni — és a hamis bizonyosság rosszabb, mint a bevallott bizonytalanság: az
 * ügyfél az előbbi alapján törölne ki jó címeket a listájáról.
 */
final class AddressValidator
{
    public function __construct(
        private readonly EmailSyntax $syntax,
        private readonly TypoSuggester $typos,
        private readonly DnsResolver $dns,
        private readonly MailboxProbe $probe,
    ) {}

    /**
     * @param  bool|null  $withProbe  `null` = a konfiguráció dönt. A gate-út explicit `false`-t
     *                                ad: a küldési kérésen belül nem szondázunk.
     */
    public function validate(string $email, ?bool $withProbe = null): ValidationVerdict
    {
        $raw = trim($email);
        $normalized = DomainNormalizer::email($raw);

        // MINDEN kulcs jelen van, a korai kilépésnél is: az integrátor nem indexelhet vakon
        // egy hiányzó kulcsra.
        $checks = [
            'syntax' => false,
            'mx' => false,
            'role' => true,
            'disposable' => true,
            'typo' => true,
            'catch_all' => true,
            'probe' => 'skipped',
            'probe_ok' => true,
        ];

        $reasons = [];
        $risk = 0.0;

        // 1. SZINTAXIS — ingyen van, és ha itt bukik, minden további kérdés értelmetlen.
        $syntaxError = $this->syntax->reason($raw);

        $checks['syntax'] = $syntaxError === null;

        if ($syntaxError !== null) {
            return new ValidationVerdict(
                email: $raw,
                classification: ValidationVerdict::UNDELIVERABLE,
                risk: 1.0,
                checks: $checks,
                reasons: ['SYNTAX_INVALID'],
                suggestion: $this->typos->suggestFor($raw),
            );
        }

        $domain = DomainNormalizer::ofEmail($normalized);
        $local = strtolower(substr($normalized, 0, (int) strrpos($normalized, '@')));

        // 2. ELGÉPELÉS — a domain-ellenőrzés ELŐTT, mert egy `gmial.com`-nak véletlenül lehet
        // MX-e (parkoltatott domain), és akkor a javaslat elmaradna.
        $suggestion = $this->typos->suggestFor($normalized);

        $checks['typo'] = $suggestion === null;

        if ($suggestion !== null) {
            $risk += (float) config('validation.weights.typo');
            $reasons[] = 'POSSIBLE_TYPO';
        }

        // 3. DOMAIN + MX — ténykérdés. MX nélkül a levél SEHOVA nem megy.
        $hasMx = $this->hasMailExchanger($domain);

        $checks['mx'] = $hasMx;

        if (! $hasMx) {
            return new ValidationVerdict(
                email: $raw,
                classification: ValidationVerdict::UNDELIVERABLE,
                risk: 1.0,
                checks: $checks,
                reasons: [...$reasons, 'NO_MX_RECORD'],
                suggestion: $suggestion,
            );
        }

        // 4. SZEREPCÍM — nem személy, hanem funkció. Nem tiltjuk, jelezzük.
        $isRole = in_array($local, (array) config('validation.role_addresses'), true);

        $checks['role'] = ! $isRole;

        if ($isRole) {
            $risk += (float) config('validation.weights.role');
            $reasons[] = 'ROLE_ADDRESS';
        }

        // 5. ELDOBHATÓ DOMAIN — garantáltan bounce-ol vagy sosem olvassák.
        $isDisposable = in_array($domain, (array) config('validation.disposable_domains'), true);

        $checks['disposable'] = ! $isDisposable;

        if ($isDisposable) {
            $risk += (float) config('validation.weights.disposable');
            $reasons[] = 'DISPOSABLE_DOMAIN';
        }

        // 6–7. SZONDA és CATCH-ALL — a legdrágább lépés, ezért utoljára.
        $probeEnabled = $withProbe ?? (bool) config('validation.probe.enabled');

        $probe = $probeEnabled
            ? $this->probe->probe($normalized)
            // A KIKAPCSOLT szonda NEM bizonytalanság: mi döntöttünk úgy, hogy nem kérdezünk.
            // A korábbi változat `unknown`-t adott vissza, és ezzel MINDEN címzettre +0.20
            // alapkockázatot rakott a küldési úton — egy 0.4-es küszöböt választó tenant
            // elveszítette volna az összes `info@`/`support@` tranzakciós levelét.
            : ProbeResult::skipped();

        $checks['probe'] = $probe->status;
        $checks['probe_ok'] = in_array($probe->status, ['accepted', 'skipped'], true);
        // A `checks` MINDENHOL azt jelenti: „rendben van-e". A `catch_all` kulcsnál ez azt
        // jelenti, hogy NEM catch-all — különben az integrátornak kulcsonként kellene tudnia,
        // melyik irányba olvassa.
        $checks['catch_all'] = $probe->status !== 'catch_all';

        [$risk, $reasons, $classification] = $this->applyProbe($probe, $risk, $reasons);

        // A KOCKÁZAT akkor is felülírja a bizonytalanságot, ha a szonda nem tudott dönteni:
        // egy eldobható domainű, elgépelt cím nem `unknown`, hanem rossz.
        if ($classification === ValidationVerdict::UNKNOWN
            && $risk >= (float) config('validation.thresholds.undeliverable')) {
            $classification = ValidationVerdict::UNDELIVERABLE;
        }

        return new ValidationVerdict(
            email: $raw,
            classification: $classification ?? $this->classify($risk),
            risk: min(1.0, $risk),
            checks: $checks,
            reasons: $reasons,
            suggestion: $suggestion,
        );
    }

    /**
     * @param  list<string>  $reasons
     * @return array{0: float, 1: list<string>, 2: ?string}
     */
    private function applyProbe(ProbeResult $probe, float $risk, array $reasons): array
    {
        return match ($probe->status) {
            // A fogadó KIMONDTA, hogy nincs ilyen postafiók. Ennél biztosabb jel nincs.
            'rejected' => [1.0, [...$reasons, 'MAILBOX_NOT_FOUND'], ValidationVerdict::UNDELIVERABLE],

            // A catch-all szerver MINDENT elfogad: nem azt mondja, hogy a cím létezik, hanem
            // azt, hogy nem árulja el. A különbséget csak a tényleges küldés mutatná meg.
            'catch_all' => [
                $risk + (float) config('validation.weights.catch_all'),
                [...$reasons, 'CATCH_ALL_DOMAIN'],
                null,
            ],

            // A BIZONYTALAN szonda `unknown` MINŐSÍTÉST ad, nem csak pontszámot.
            //
            // Ez volt a modul legsúlyosabb hibája: a `probe_unknown` súly (0.20) a `risky`
            // küszöb (0.25) ALATT van, tehát minden eldönthetetlen szonda `deliverable`-t
            // adott. Mérve: 200 gmail-cím, 5 valódi szonda (a keret miatt) → 195 sosem
            // ellenőrzött cím „kézbesíthető" minősítéssel. Egy tízezres listán ~9995 hamis
            // „deliverable" — és az ügyfél PONT ezért fizet. A rá küldött kampány
            // bounce-rátája a golden rule 8-at viszi el.
            //
            // Az `unknown` nem szégyen: azt jelenti, hogy nem tudtuk megnézni. A hamis
            // bizonyosság rosszabb — az ügyfél az alapján küldene.
            'unknown' => [
                $risk + (float) config('validation.weights.probe_unknown'),
                [...$reasons, 'PROBE_INCONCLUSIVE'],
                ValidationVerdict::UNKNOWN,
            ],

            // `skipped`: nem kérdeztünk. Se pontszám, se ok — a saját döntésünkért nem
            // büntetjük a címet.
            default => [$risk, $reasons, null],
        };
    }

    /**
     * A MINŐSÍTÉS a küszöbökből.
     *
     * A `risky` sáv szándékosan széles: a „valószínűleg jó, de nem biztos" a leggyakoribb
     * valóság, és az ügyfélnek EZT kell látnia, nem egy hamis bizonyosságot.
     */
    private function classify(float $risk): string
    {
        if ($risk >= (float) config('validation.thresholds.undeliverable')) {
            return ValidationVerdict::UNDELIVERABLE;
        }

        return $risk >= (float) config('validation.thresholds.risky')
            ? ValidationVerdict::RISKY
            : ValidationVerdict::DELIVERABLE;
    }

    private function hasMailExchanger(string $domain): bool
    {
        if ($domain === '') {
            return false;
        }

        if ($this->dns->records($domain, 'MX') !== []) {
            return true;
        }

        // RFC 5321 §5.1: MX hiányában az A/AAAA rekord IMPLICIT MX. Aki ezt kihagyja, a
        // saját szerverén futó kis cégek címeit hibásan „kézbesíthetetlennek" minősíti.
        return $this->dns->records($domain, 'A') !== [] || $this->dns->records($domain, 'AAAA') !== [];
    }
}
