<?php

declare(strict_types=1);

namespace App\Modules\Deliverability\Services;

use App\Modules\Deliverability\Contracts\BlocklistClient;
use App\Modules\Deliverability\Data\SpamScore;
use App\Modules\Sending\Services\DomainNormalizer;

/**
 * KÜLDÉS ELŐTTI spam-pontszám (46. kritérium).
 *
 * SZABÁLY-ALAPÚ, nem statisztikai — és ez tudatos döntés. Egy fekete doboz „0.7"-e semmit nem
 * ér az ügyfélnek: nem tudja, mit rontott el, és nem tudja, mit javítson. Minden pont mellé
 * KONKRÉT javaslat jár.
 *
 * A pontszám NEM BLOKKOL. A küldés joga a tenanté; a mi dolgunk megmondani, mire számíthat.
 * Egy nagybetűs tárgysorral kimenő levél nem hiba — csak rosszabbul teljesít.
 */
final class SpamScorer
{
    /**
     * Hány KÜLÖNBÖZŐ link-domaint kérdezünk le a domain-feketelistán.
     *
     * A pontozás egy szinkron, felhasználó által indított művelet: száz DNS-lekérdezés
     * időtúllépésbe futna, és a lassú válasz itt rosszabb, mint a hiányzó találat.
     */
    private const MAX_DOMAIN_LOOKUPS = 10;

    public function __construct(private readonly BlocklistClient $blocklist) {}

    /** @param list<string> $links a törzsből kiszedett URL-ek */
    public function score(?string $subject, ?string $html, ?string $text, array $links = []): SpamScore
    {
        $findings = [];
        $score = 0.0;

        $subject = trim((string) $subject);
        $body = trim((string) ($text ?? '')).' '.$this->visibleText((string) ($html ?? ''));

        // TÁRGY
        if ($subject === '') {
            $score += 2.0;
            $findings[] = ['rule' => 'EMPTY_SUBJECT', 'points' => 2.0, 'advice' => 'Adj tárgyat a levélnek: a tárgy nélküli levelet a szűrők többsége eleve gyanúsnak veszi.'];
        }

        if ($subject !== '' && mb_strlen($subject) > 90) {
            $score += 0.5;
            $findings[] = ['rule' => 'LONG_SUBJECT', 'points' => 0.5, 'advice' => 'Rövidítsd a tárgyat 90 karakter alá: a mobil kliensek levágják, és a szűrők a hosszú tárgyat is jelzik.'];
        }

        if ($subject !== '' && $this->shoutedRatio($subject) > 0.5 && mb_strlen($subject) > 8) {
            $score += 1.5;
            $findings[] = ['rule' => 'SHOUTING_SUBJECT', 'points' => 1.5, 'advice' => 'Ne írd csupa nagybetűvel a tárgyat — ez az egyik legrégebbi spam-jel.'];
        }

        if (preg_match_all('/[!?]/', $subject) > 2) {
            $score += 1.0;
            $findings[] = ['rule' => 'EXCESSIVE_PUNCTUATION', 'points' => 1.0, 'advice' => 'Csökkentsd a felkiáltó- és kérdőjelek számát a tárgyban.'];
        }

        // TÖRZS
        if (trim($body) === '') {
            $score += 3.0;
            $findings[] = ['rule' => 'EMPTY_BODY', 'points' => 3.0, 'advice' => 'A levélnek legyen szöveges tartalma.'];
        }

        if (($html ?? '') !== '' && ($text ?? '') === '') {
            // A `text/plain` változat hiánya klasszikus jel: a valódi levelezők küldik,
            // a tömeges spam-küldők gyakran nem.
            $score += 1.0;
            $findings[] = ['rule' => 'NO_PLAIN_TEXT', 'points' => 1.0, 'advice' => 'Adj sima szöveges (plain text) változatot is a HTML mellé.'];
        }

        if (($html ?? '') !== '' && $this->imageToTextRatio((string) $html) > 0.8) {
            $score += 1.5;
            $findings[] = ['rule' => 'IMAGE_HEAVY', 'points' => 1.5, 'advice' => 'Több szöveget tegyél a levélbe: a csak képből álló levél a szűrőknek olvashatatlan, és a képblokkolás miatt az olvasónak is.'];
        }

        // LINKEK
        $score += $this->scoreLinks($links, $findings);

        return new SpamScore(round($score, 2), $findings);
    }

    /**
     * @param  list<string>  $links
     * @param  list<array<string, mixed>>  $findings
     */
    private function scoreLinks(array $links, array &$findings): float
    {
        $score = 0.0;
        $shorteners = (array) config('deliverability.spam_score.shorteners');
        $found = [];

        foreach ($links as $link) {
            $host = strtolower((string) parse_url((string) $link, PHP_URL_HOST));

            if ($host === '') {
                continue;
            }

            $host = DomainNormalizer::canonical($host) ?: $host;

            if (in_array($host, $shorteners, true) && ! in_array($host, $found, true)) {
                $found[] = $host;
            }
        }

        if ($found !== []) {
            // A rövidítők elrejtik a valódi célt: a szűrők nem tudják ellenőrizni, hova
            // visznek, ezért régóta gyanúsnak veszik őket.
            $score += 2.0;

            $findings[] = [
                'rule' => 'URL_SHORTENER',
                'points' => 2.0,
                'advice' => 'Cseréld a rövidítő-linkeket teljes URL-re ('.implode(', ', $found).'): a szűrők nem tudják ellenőrizni, hova visznek.',
            ];
        }

        $score += $this->scoreBlocklistedDomains($links, $findings);

        if (count($links) > 30) {
            $score += 1.0;
            $findings[] = ['rule' => 'MANY_LINKS', 'points' => 1.0, 'advice' => 'Csökkentsd a linkek számát: a nagyon sok link tömeges hirdetésre utal.'];
        }

        return $score;
    }

    /**
     * A LINK-DOMAINEK a domain-feketelistán (`dbl.spamhaus.org`).
     *
     * A küldő IP-je lehet makulátlan: ha a levélben lévő link egy listás domainre mutat, a
     * fogadók a LEVELET minősítik spamnek. Ez a leggyakoribb eset a feltört ügyfélfióknál és
     * az újrahasznosított sablonoknál — az ügyfél nem is tudja, hogy a lábléc egy régi,
     * azóta elárverezett domainre mutat.
     *
     * Az ELDÖNTHETETLEN válasz (DNS-hiba, szolgáltatói hibajelzés) NEM pont: a hamis
     * riasztás ugyanúgy rombolja a bizalmat, mint az elmaradt.
     *
     * @param  list<string>  $links
     * @param  list<array<string, mixed>>  $findings
     */
    private function scoreBlocklistedDomains(array $links, array &$findings): float
    {
        $zone = (string) config('deliverability.spam_score.domain_blocklist_zone');

        if ($zone === '') {
            return 0.0;
        }

        $listed = [];
        $checked = 0;

        foreach ($this->linkHosts($links) as $host) {
            if ($checked >= self::MAX_DOMAIN_LOOKUPS) {
                break;
            }

            $checked++;

            if ($this->blocklist->check($host, $zone)->listed) {
                $listed[] = $host;
            }
        }

        if ($listed === []) {
            return 0.0;
        }

        $findings[] = [
            'rule' => 'BLOCKLISTED_DOMAIN',
            'points' => 3.0,
            'advice' => 'A levélben szereplő domain feketelistán van ('.implode(', ', $listed).'): '
                .'a fogadók a LEVELET minősítik spamnek, akkor is, ha a küldő IP tiszta. Vedd ki a linket.',
        ];

        return 3.0;
    }

    /**
     * A linkek EGYEDI hosztjai, a saját nyomkövetési hosztunk nélkül.
     *
     * @param  list<string>  $links
     * @return list<string>
     */
    private function linkHosts(array $links): array
    {
        $hosts = [];

        foreach ($links as $link) {
            $host = strtolower((string) parse_url((string) $link, PHP_URL_HOST));

            if ($host === '' || in_array($host, $hosts, true)) {
                continue;
            }

            $hosts[] = DomainNormalizer::canonical($host) ?: $host;
        }

        return array_values(array_unique($hosts));
    }

    /**
     * A csupa nagybetűs betűk aránya — a HÁROMNÁL RÖVIDEBB szavak nélkül.
     *
     * A rövid, csupa nagybetűs szó jellemzően mozaikszó (ÁFA, EU, KFT, NAV), nem kiabálás.
     * Nélküle az „ÁFA IDŐ 2026" tárgy 1.5 pontot kapott — és a hamis találat pont az a
     * fajta, amitől az ügyfél elkezdi figyelmen kívül hagyni az egész pontszámot.
     */
    private function shoutedRatio(string $text): float
    {
        $words = preg_split('/\s+/u', $text) ?: [];

        $text = implode(' ', array_filter(
            $words,
            static fn (string $word) => mb_strlen((string) preg_replace('/[^\p{L}]/u', '', $word)) >= 3,
        ));

        $letters = preg_replace('/[^\p{L}]/u', '', $text) ?? '';

        if ($letters === '') {
            return 0.0;
        }

        $upper = preg_replace('/[^\p{Lu}]/u', '', $letters) ?? '';

        return mb_strlen($upper) / mb_strlen($letters);
    }

    /**
     * A HTML LÁTHATÓ szövege.
     *
     * A `strip_tags()` a `<style>` és a `<script>` blokk TARTALMÁT meghagyja, tehát a CSS
     * „szövegnek" számítana. Mivel a ventMail sablonmotorja MJML, MINDEN valódi levélben van
     * style-blokk — enélkül a két tartalmi szabály (üres törzs, csak-képes levél) a
     * gyakorlatban SOHA nem tüzelt volna: egy csak képet tartalmazó levél 40 sornyi CSS-sel
     * 5.5 pont helyett 1.0-t kapott.
     */
    private function visibleText(string $html): string
    {
        $html = (string) preg_replace('#<(style|script)\b[^>]*>.*?</\1\s*>#is', ' ', $html);

        // A LEZÁRATLAN blokk is ide tartozik: a `<style>` a levél végéig „nyelné" a
        // tartalmat, a lezáratlan változatnál viszont a `strip_tags` visszaadná a CSS-t.
        $html = (string) preg_replace('#<(style|script)\b[^>]*>.*$#is', ' ', $html);

        return trim(strip_tags($html));
    }

    private function imageToTextRatio(string $html): float
    {
        $images = preg_match_all('/<img\b/i', $html);
        $text = mb_strlen($this->visibleText($html));

        if ($images === 0) {
            return 0.0;
        }

        // Durva, de átlátható arány: képenként ~200 karakter szöveget várunk el.
        return $text === 0 ? 1.0 : min(1.0, ($images * 200) / ($text + $images * 200));
    }
}
