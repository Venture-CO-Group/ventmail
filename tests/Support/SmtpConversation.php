<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Smtp\Contracts\SubmissionSink;
use App\Modules\Smtp\Data\ChannelSecurity;
use App\Modules\Smtp\Protocol\SmtpSession;
use App\Modules\Smtp\Services\CredentialAuthenticator;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;

/**
 * Egy teljes SMTP-párbeszéd lejátszása a VALÓDI hitelesítővel és a VALÓDI ingesttel.
 *
 * Osztályban, nem globális teszt-helperként: párhuzamos futásnál a globális függvény csak
 * abban a processzben létezik, ahol a deklaráló teszt-fájl betöltődött — a másikban
 * „undefined function" lenne (pontosan ez történt).
 */
final class SmtpConversation
{
    /**
     * @param  list<string>  $lines
     * @return list<int> a válaszkódok
     */
    public static function play(string $username, string $password, array $lines, Workspace $restoreContext): array
    {
        $session = new SmtpSession(
            app(CredentialAuthenticator::class),
            app(SubmissionSink::class),
            ChannelSecurity::encrypted('TLSv1.3'),
            'smtp.ventmail.test',
        );

        $codes = [];

        $script = array_merge(
            ['EHLO kliens.test', 'AUTH PLAIN '.base64_encode("\0".$username."\0".$password)],
            $lines
        );

        foreach ($script as $line) {
            foreach ($session->feed($line) as $reply) {
                $codes[] = $reply->code;
            }
        }

        // Az ingest ELENGEDI a kontextust (hosszan élő processz) — a teszt visszaállítja.
        app(WorkspaceContext::class)->set($restoreContext);

        return $codes;
    }

    /**
     * Egy szokásos levél sorai.
     *
     * @param  array<string, string>  $headers
     * @return list<string>
     */
    public static function message(array $headers = [], string $body = 'Szia'): array
    {
        $lines = ['MAIL FROM:<no-reply@pelda.hu>', 'RCPT TO:<cimzett@example.test>', 'DATA'];

        foreach (array_merge(['From' => 'no-reply@pelda.hu', 'Subject' => 'Teszt'], $headers) as $name => $value) {
            $lines[] = $name.': '.$value;
        }

        return array_merge($lines, ['', $body, '.']);
    }
}
