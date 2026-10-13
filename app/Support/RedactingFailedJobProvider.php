<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeInterface;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Failed\PrunableFailedJobProvider;

/**
 * A `failed_jobs` tárolás DEKORÁTORA: a QueryException-t (kötések, DETAIL/CONTEXT, értékek az
 * üzenetben) redaktálja a naplózás ELŐTT. A Laravel alapból a nyers kivétel szövegét és trace-ét
 * írja (`WorkCommand::logFailedJob` → `log()`), ami a beszúrt címeket és neveket is tartalmazhatja.
 */
final class RedactingFailedJobProvider implements CountableFailedJobProvider, FailedJobProviderInterface, PrunableFailedJobProvider
{
    public function __construct(private readonly FailedJobProviderInterface $inner) {}

    public function log($connection, $queue, $payload, $exception)
    {
        return $this->inner->log($connection, $queue, $payload, RedactQueryException::throwable($exception));
    }

    public function ids($queue = null)
    {
        return $this->inner->ids($queue);
    }

    public function all()
    {
        return $this->inner->all();
    }

    public function find($id)
    {
        return $this->inner->find($id);
    }

    public function forget($id)
    {
        return $this->inner->forget($id);
    }

    public function flush($hours = null)
    {
        $this->inner->flush($hours);
    }

    public function prune(DateTimeInterface $before)
    {
        return $this->inner instanceof PrunableFailedJobProvider ? $this->inner->prune($before) : 0;
    }

    public function count($connection = null, $queue = null)
    {
        return $this->inner instanceof CountableFailedJobProvider ? $this->inner->count($connection, $queue) : 0;
    }
}
