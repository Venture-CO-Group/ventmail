<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\QueryException;

/**
 * A QueryException ÁTALAKÍTÁSA a naplózás előtt: se a kötések, se a Postgres hibaüzenetének
 * `DETAIL:` / `CONTEXT:` / `HINT:` sorai (a beszúrt érték — cím, név — szó szerint ott van) nem
 * maradnak meg. Az eredeti `previous` kivétel üzenete a QueryException üzenetébe is beleszámít,
 * ezért a `previous`-t is cseréljük; az SQLSTATE megmarad.
 */
final class RedactQueryException
{
    public function __invoke(QueryException $e): QueryException
    {
        $previous = $e->getPrevious();
        $message = $previous?->getMessage() ?? $e->getMessage();

        // A DETAIL/CONTEXT/HINT a Postgres-üzenet végén áll, újsorral elválasztva.
        $message = (string) preg_replace('/\R(?:DETAIL|CONTEXT|HINT|STATEMENT):.*$/su', '', $message);

        $clean = new RedactedPdoException($message, (string) ($previous?->getCode() ?? $e->getCode()));

        return new QueryException($e->getConnectionName(), $e->getSql(), array_fill(0, count($e->getBindings()), '?'), $clean);
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
