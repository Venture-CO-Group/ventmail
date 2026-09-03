<?php

declare(strict_types=1);

use App\Modules\Sending\Data\SendQueue;

/*
 | Horizon — a küldési sorok PRIORITÁSSAL.
 |
 | A `queue` tömb SORRENDJE maga a szabály: a worker felülről lefelé keres munkát, tehát a
 | telített `campaign` sor mellett a `transactional` ELŐBB megy ki (golden rule 4). Ha
 | valaki itt átrendezi a sorokat, a kód-szintű prioritás semmit nem ér — ezért erre teszt is
 | van (`QueuePriorityTest`).
 */

return [
    'domain' => env('HORIZON_DOMAIN'),
    'path' => env('HORIZON_PATH', 'horizon'),
    'use' => 'default',
    'prefix' => env('HORIZON_PREFIX', 'ventmail_horizon:'),
    'middleware' => ['web', 'auth'],

    'waits' => ['redis:transactional' => 30, 'redis:journey' => 300, 'redis:campaign' => 900],
    'trim' => ['recent' => 60, 'pending' => 60, 'completed' => 60, 'recent_failed' => 10080, 'failed' => 10080, 'monitored' => 10080],
    'silenced' => [],
    'metrics' => ['trim_snapshots' => ['job' => 24, 'queue' => 24]],
    'fast_termination' => false,
    'memory_limit' => 256,

    'defaults' => [
        'sending' => [
            'connection' => 'redis',
            'queue' => SendQueue::priorityOrder(),
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 1,
            'timeout' => 120,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => ['sending' => ['maxProcesses' => 20, 'balanceMaxShift' => 3, 'balanceCooldown' => 3]],
        'local' => ['sending' => ['maxProcesses' => 3]],
        'testing' => ['sending' => ['maxProcesses' => 1]],
    ],
];
