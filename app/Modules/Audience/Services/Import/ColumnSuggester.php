<?php

declare(strict_types=1);

namespace App\Modules\Audience\Services\Import;

use Normalizer;

/**
 * OSZLOP-JAVASLAT a fájl fejléce alapján — mind a hét nyelv (hu, en, de, fr, it, es, ru)
 * gyakori elnevezéseivel.
 *
 * A célok: `email`, `first_name`, `last_name`, `attr:<kulcs>` (a kontakt `attributes`
 * JSON-jába), `null` = nincs javaslat. A párosítás ÉKEZET- ÉS KÖZ-FÜGGETLEN (`E-mail cím` =
 * `email cim` = `emailcim`), mert a fejlécet ember írta.
 */
final class ColumnSuggester
{
    /** A beépített (nem egyéni) attribútumok. */
    public const BUILTIN_ATTRIBUTES = ['company', 'phone'];

    /** @var array<string, list<string>> */
    private const DICTIONARY = [
        'email' => [
            'email', 'e-mail', 'e-mail cím', 'email cím', 'emailcim', 'e-mail-cím', 'mail', 'email address', 'e-mail address',
            'emailadresse', 'e-mail-adresse', 'courriel', 'adresse e-mail', 'adresse email', 'adresse mail',
            'correo', 'correo electrónico', 'correo electronico', 'dirección de correo', 'direccion de correo',
            'indirizzo email', 'indirizzo e-mail', 'posta elettronica',
            'почта', 'электронная почта', 'эл. почта', 'email адрес', 'адрес электронной почты', 'емейл', 'имейл',
        ],
        'first_name' => [
            'keresztnév', 'utónév', 'first name', 'firstname', 'given name', 'forename', 'vorname',
            'prénom', 'prenom', 'nome', 'nombre', 'nombre de pila', 'имя',
        ],
        'last_name' => [
            'vezetéknév', 'családnév', 'last name', 'lastname', 'surname', 'family name', 'nachname', 'familienname',
            'nom', 'nom de famille', 'cognome', 'apellido', 'apellidos', 'фамилия',
        ],
        'attr:company' => [
            'cég', 'cégnév', 'cég neve', 'vállalat', 'company', 'company name', 'organization', 'organisation',
            'firma', 'firmenname', 'unternehmen', 'société', 'societe', 'entreprise', 'azienda', 'società',
            'empresa', 'компания', 'организация', 'фирма',
        ],
        'attr:phone' => [
            'telefon', 'telefonszám', 'mobil', 'mobiltelefon', 'phone', 'phone number', 'mobile', 'telephone',
            'telefonnummer', 'handy', 'téléphone', 'telefono', 'telefon', 'teléfono', 'celular',
            'телефон', 'номер телефона', 'мобильный',
        ],
    ];

    /** @var array<string, array{target: string, exact: bool}>|null */
    private ?array $index = null;

    /**
     * @param  list<array{key: string, label: string}>  $customFields  a workspace egyéni mezői
     * @return array{target: string|null, confidence: int}
     */
    public function suggest(string $header, array $customFields = []): array
    {
        $needle = $this->compact($header);

        if ($needle === '') {
            return ['target' => null, 'confidence' => 0];
        }

        $index = $this->index();

        if (isset($index[$needle])) {
            return ['target' => $index[$needle], 'confidence' => 100];
        }

        // A WORKSPACE saját mezői: a kulcs vagy a címke egyezése. A beépítettek elé NEM
        // kerülnek (a `phone` ne legyen egyéni mező felülírható), de az ismeretlen fejlécre
        // ez a találat.
        foreach ($customFields as $field) {
            if ($needle === $this->compact($field['key']) || $needle === $this->compact($field['label'])) {
                return ['target' => 'attr:'.$field['key'], 'confidence' => 95];
            }
        }

        // RÉSZLEGES találat: csak elég hosszú (>= 5) szótári kulcsra, különben a `nom` a
        // `prenom`-ra és a `mail` mindenre illene.
        $best = null;
        $bestLength = 0;

        foreach ($index as $token => $target) {
            $length = strlen($token);

            if ($length >= 5 && $length > $bestLength && str_contains($needle, $token)) {
                $best = $target;
                $bestLength = $length;
            }
        }

        return $best === null
            ? ['target' => null, 'confidence' => 0]
            : ['target' => $best, 'confidence' => 85];
    }

    /** @return array<string, string> kompakt szó → cél */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $index = [];

        foreach (self::DICTIONARY as $target => $names) {
            foreach ($names as $name) {
                $index[$this->compact($name)] ??= $target;
            }
        }

        return $this->index = $index;
    }

    /** Kisbetű, ékezet nélkül, csak betű/szám — a Unicode-ot (cirill) megőrzi. */
    private function compact(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $decomposed = class_exists(Normalizer::class) ? Normalizer::normalize($value, Normalizer::FORM_D) : $value;
        $value = preg_replace('/\p{Mn}+/u', '', (string) $decomposed) ?? $value;

        return preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?? '';
    }
}
