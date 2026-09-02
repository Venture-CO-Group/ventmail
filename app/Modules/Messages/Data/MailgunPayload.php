<?php

declare(strict_types=1);

namespace App\Modules\Messages\Data;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A Mailgun-kompatibilis kérés-payload értelmezése.
 *
 * Miért külön osztály: a `o:`/`h:`/`v:` prefixelt mezők nem illeszkednek a Laravel
 * validációjához, és a Mailgun szemantikája sok helyen „elnéző" (`yes`/`true`/`1`). Az M1
 * globális DoD-ja az, hogy a MEGLÉVŐ integráció kódmódosítás nélkül átjöjjön — tehát ezt a
 * szemantikát pontosan kell követni, nem szépítve.
 */
final readonly class MailgunPayload
{
    /** A Mailgun szerint hívásonként max 1000 címzett. */
    public const MAX_RECIPIENTS = 1000;

    /** Max 3 tag üzenetenként. */
    public const MAX_TAGS = 3;

    /** Az ütemezés felső korlátja. */
    public const MAX_SCHEDULE_DAYS = 7;

    private function __construct(
        public string $fromEmail,
        public ?string $fromName,
        /** @var list<string> */
        public array $recipients,
        public ?string $subject,
        public ?string $html,
        public ?string $text,
        /** @var list<string> */
        public array $tags,
        /** @var array<string, mixed> */
        public array $variables,
        /** @var array<string, string> */
        public array $headers,
        /** @var array<string, mixed> */
        public array $options,
        public ?Carbon $deliverytime,
        public bool $testmode,
        public ?string $ipPool,
        public ?string $template,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws InvalidArgumentException a hívó ezt fordítja API-hibává (kód + üzenet)
     */
    public static function fromRequest(array $input): self
    {
        [$fromEmail, $fromName] = self::parseFrom((string) ($input['from'] ?? ''));

        $recipients = self::parseRecipients($input['to'] ?? []);

        if ($recipients === []) {
            throw new InvalidArgumentException('MISSING_RECIPIENT|Legalább egy címzett kötelező (`to`).');
        }

        if (count($recipients) > self::MAX_RECIPIENTS) {
            throw new InvalidArgumentException(sprintf(
                'TOO_MANY_RECIPIENTS|Hívásonként legfeljebb %d címzett adható meg (%d érkezett).',
                self::MAX_RECIPIENTS,
                count($recipients)
            ));
        }

        $tags = self::listOf($input['o:tag'] ?? []);

        if (count($tags) > self::MAX_TAGS) {
            throw new InvalidArgumentException(sprintf(
                'TOO_MANY_TAGS|Üzenetenként legfeljebb %d tag adható meg.',
                self::MAX_TAGS
            ));
        }

        return new self(
            fromEmail: $fromEmail,
            fromName: $fromName,
            recipients: $recipients,
            subject: isset($input['subject']) ? (string) $input['subject'] : null,
            html: isset($input['html']) ? (string) $input['html'] : null,
            text: isset($input['text']) ? (string) $input['text'] : null,
            tags: $tags,
            variables: self::parseVariables($input),
            headers: self::parsePrefixed($input, 'h:'),
            options: self::parseOptions($input),
            deliverytime: self::parseDeliverytime($input['o:deliverytime'] ?? null),
            testmode: self::truthy($input['o:testmode'] ?? false),
            ipPool: isset($input['o:sending-ip-pool']) ? (string) $input['o:sending-ip-pool'] : null,
            template: isset($input['template']) ? (string) $input['template'] : null,
        );
    }

    /** @return array{0: string, 1: ?string} */
    private static function parseFrom(string $from): array
    {
        $from = trim($from);

        if ($from === '') {
            throw new InvalidArgumentException('MISSING_FROM|A `from` mező kötelező.');
        }

        // „Név <cim@pelda.hu>" vagy „cim@pelda.hu"
        if (preg_match('/^(.*)<\s*([^>]+)\s*>$/', $from, $matches) === 1) {
            $name = trim($matches[1], " \t\"'");

            return [strtolower(trim($matches[2])), $name !== '' ? $name : null];
        }

        return [strtolower($from), null];
    }

    /** @return list<string> */
    private static function parseRecipients(mixed $to): array
    {
        // A Mailgun vesszővel is elfogadja: „a@x.hu, b@y.hu".
        $values = is_string($to) ? explode(',', $to) : (array) $to;

        return array_values(array_filter(array_map(
            static fn ($value) => strtolower(trim((string) $value)),
            $values
        ), static fn (string $value) => $value !== ''));
    }

    /** @param array<string, mixed> $input */
    private static function parseVariables(array $input): array
    {
        $variables = self::parsePrefixed($input, 'v:');

        // A batch-behelyettesítés JSON-ként érkezik, címzett → változók leképezéssel.
        if (isset($input['recipient-variables'])) {
            $decoded = is_array($input['recipient-variables'])
                ? $input['recipient-variables']
                : json_decode((string) $input['recipient-variables'], true);

            if (! is_array($decoded)) {
                throw new InvalidArgumentException(
                    'INVALID_RECIPIENT_VARIABLES|A `recipient-variables` érvényes JSON objektum legyen.'
                );
            }

            $variables['recipient-variables'] = $decoded;
        }

        return $variables;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    private static function parsePrefixed(array $input, string $prefix): array
    {
        $result = [];

        foreach ($input as $key => $value) {
            if (Str::startsWith($key, $prefix)) {
                $result[Str::after($key, $prefix)] = is_array($value) ? $value : (string) $value;
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $input */
    private static function parseOptions(array $input): array
    {
        $options = [];

        $map = [
            'o:tracking' => 'tracking',
            'o:dkim' => 'dkim',
            'o:require-tls' => 'require_tls',
            'o:skip-verification' => 'skip_verification',
        ];

        foreach ($map as $key => $name) {
            if (array_key_exists($key, $input)) {
                $options[$name] = self::truthy($input[$key]);
            }
        }

        // A klikk- és nyitáskövetés háromértékű: yes | no | htmlonly.
        foreach (['o:tracking-clicks' => 'tracking_clicks', 'o:tracking-opens' => 'tracking_opens'] as $key => $name) {
            if (! array_key_exists($key, $input)) {
                continue;
            }

            $value = strtolower(trim((string) $input[$key]));

            $options[$name] = $value === 'htmlonly' ? 'htmlonly' : self::truthy($value);
        }

        return $options;
    }

    private static function parseDeliverytime(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $when = Carbon::parse((string) $value);
        } catch (\Throwable) {
            throw new InvalidArgumentException('INVALID_DELIVERYTIME|Az `o:deliverytime` RFC 2822 formátumú legyen.');
        }

        if ($when->isPast()) {
            throw new InvalidArgumentException('DELIVERYTIME_IN_PAST|Az `o:deliverytime` nem lehet a múltban.');
        }

        if ($when->gt(now()->addDays(self::MAX_SCHEDULE_DAYS))) {
            throw new InvalidArgumentException(sprintf(
                'DELIVERYTIME_TOO_FAR|Az ütemezés legfeljebb %d nappal előre lehet.',
                self::MAX_SCHEDULE_DAYS
            ));
        }

        return $when;
    }

    /** @return list<string> */
    private static function listOf(mixed $value): array
    {
        return array_values(array_filter(array_map(
            static fn ($item) => trim((string) $item),
            is_array($value) ? $value : [$value]
        ), static fn (string $item) => $item !== ''));
    }

    /** A Mailgun elnéző: `yes` / `true` / `1` mind igaz. */
    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['yes', 'true', '1', 'on'], true);
    }
}
