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
        /*
         | A KÜLDÉSI sorok: prioritás szerint (transactional > journey > campaign).
         */
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

        /*
         | A HÁTTÉRMUNKA sorai — külön supervisor.
         |
         | Ezek NEM küldési sorok: ha ugyanabba a supervisorba kerülnének, egy nagy bulk
         | ellenőrzés vagy egy lassú inbound parse a TRANZAKCIÓS levelek elé állna. És ami
         | ennél is fontosabb: a `default` sort a küldési supervisor EGYÁLTALÁN nem fogyasztja
         | — az oda tett job örökre a sorban maradna, csendben.
         |
         | A hosszabb `timeout` a bejövő MIME-parse és a szonda miatt kell (a szonda maga 10
         | másodpercet is várhat egy lassú fogadóra).
         */
        'background' => [
            'connection' => 'redis',
            'queue' => ['validation', 'inbound', 'webhooks', 'default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 512,
            'tries' => 1,
            'timeout' => 300,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'sending' => ['maxProcesses' => 20, 'balanceMaxShift' => 3, 'balanceCooldown' => 3],
            'background' => ['maxProcesses' => 6],
        ],
        'local' => [
            'sending' => ['maxProcesses' => 3],
            'background' => ['maxProcesses' => 2],
        ],
        'testing' => [
            'sending' => ['maxProcesses' => 1],
            'background' => ['maxProcesses' => 1],
        ],
    ],
];
