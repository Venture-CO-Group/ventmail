<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\QueryException;
use Throwable;

/**
 * A QueryException ÁTALAKÍTÁSA a naplózás (és a failed-job tárolás) előtt: csak az SQLSTATE és a
 * hibaosztály marad meg. Se a kötések, se a Postgres hibaüzenete NEM — nem csak a `DETAIL:` /
 * `CONTEXT:` sorai, hanem az első sor is hordozhat értéket (`invalid input syntax for type
 * integer: "carol@example.hu"`), ezért az üzenetet nem szűrjük, hanem eldobjuk.
 */
final class RedactQueryException
{
    public function __invoke(QueryException $e): QueryException
    {
        $previous = $e->getPrevious();
        $sqlstate = (string) ($previous?->getCode() ?? $e->getCode());

        // A kód nem mindig SQLSTATE (egész szám): ilyenkor az üzenet `SQLSTATE[xxxxx]` kezdetéből olvassuk.
        if (preg_match('/^[0-9A-Z]{5}$/', $sqlstate) !== 1 && preg_match('/SQLSTATE\[([0-9A-Z]{5})\]/', $previous?->getMessage() ?? $e->getMessage(), $match) === 1) {
            $sqlstate = $match[1];
        }

        $class = $previous !== null ? $previous::class : $e::class;

        return new QueryException(
            $e->getConnectionName(),
            $e->getSql(),
            array_fill(0, count($e->getBindings()), '?'),
            new RedactedPdoException("SQLSTATE[{$sqlstate}]: database error (redacted, {$class})", $sqlstate),
        );
    }

    /** A kivétel (vagy a láncában lévő QueryException) redaktált változata; más kivételt érintetlenül ad vissza. */
    public static function throwable(Throwable $e): Throwable
    {
        for ($cursor = $e; $cursor !== null; $cursor = $cursor->getPrevious()) {
            if ($cursor instanceof QueryException) {
                return (new self)($cursor);
            }
        }

        return $e;
    }
}

/** A PDOException kódja az SQLSTATE sztring — a konstruktor csak egész számot fogad, ezért saját osztály. */
final class RedactedPdoException extends \PDOException
{
    public function __construct(string $message, string $sqlstate)
    {
        parent::__construct($message);

        $this->code = $sqlstate;
    }
}
