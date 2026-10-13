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

/*
 | A SUPERVISOR-DEFINÍCIÓK — a környezetek ezekből építkeznek (lásd a `defaults` megjegyzését).
 */
$supervisors = [
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

    /*
     | A KONTAKT-IMPORT (v1.1): külön supervisor, külön kapcsolat. Egy 300 000 soros fájl
     | percekig fut — a `background` 300 mp-es timeoutja és a Redis alap 90 mp-es
     | `retry_after`-e alatt megszakadna, illetve kétszer futna. Egyetlen folyamat elég:
     | az import a saját tranzakcióiban ír, és egy workspace importjait úgysem érdemes
     | párhuzamosítani.
     */
    'imports' => [
        'connection' => 'redis_imports',
        'queue' => ['imports'],
        'balance' => 'simple',
        'maxProcesses' => 1,
        'maxTime' => 0,
        'maxJobs' => 0,
        'memory' => 512,
        'tries' => 1,
        'timeout' => 3600,
        'nice' => 0,
    ],

    /*
     | A KONTAKT-IMPORT FELMÉRÉSE (D71): KÜLÖN queue és supervisor. A felmérés drága, de korlátos
     | (60 mp timeout, csomópont-plafonok, felmérés-kapu); a `imports` supervisort (a hosszú
     | futásokat) soha nem állíthatja meg, és a futások sem várakoztatják a felmérést.
     */
    'imports-inspect' => [
        'connection' => 'redis_imports_inspect',
        'queue' => ['imports-inspect'],
        'balance' => 'simple',
        'maxProcesses' => 1,
        'maxTime' => 0,
        'maxJobs' => 0,
        'memory' => 512,
        'tries' => 1,
        'timeout' => 60,
        'nice' => 0,
    ],
    /*
     | A KAMPÁNY MATERIALIZÁLÁSA (review BN1): külön supervisor és kapcsolat. A `StartCampaignJob` egy
     | 300k-s célzást percekig ír fel — a `sending` supervisor 120 mp-es timeoutja megölné, a Redis
     | alap 90 mp-es `retry_after`-e pedig kétszer futtatná. Itt timeout 900 < retry_after 960. A
     | felírás kötegelt (1000 sor), a memória korlátos — a küldő worker-konténerben fut, de NEM
     | foglal küldő-folyamatot, így a tranzakciós sor nem vár rá (golden rule 4).
     */
    'campaign-start' => [
        'connection' => 'redis_campaign_start',
        'queue' => ['campaign-start'],
        'balance' => 'simple',
        'maxProcesses' => 1,
        'maxTime' => 0,
        'maxJobs' => 0,
        'memory' => 256,
        'tries' => 3,
        'timeout' => 900,
        'nice' => 0,
    ],

    /*
     | v1.1 · 8. modul — a PDF-RIPORT renderelése (Gotenberg-hívás + adatgyűjtés). Külön supervisor és
     | kapcsolat (`redis_reports`, retry_after 180 > timeout 120): egy lassú Chromium-futás sem a küldő,
     | sem a háttér-sort nem foglalja. Egy folyamat — a sidecar is egyszerre egy-két renderelést bír.
     */
    'reports' => [
        'connection' => 'redis_reports',
        'queue' => ['reports'],
        'balance' => 'simple',
        'maxProcesses' => 1,
        'maxTime' => 0,
        'maxJobs' => 0,
        'memory' => 256,
        'tries' => 2,
        'timeout' => 120,
        'nice' => 0,
    ],
];

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

    /*
     | A `defaults` SZÁNDÉKOSAN ÜRES (review B5). A Horizon `ProvisioningPlan` a `defaults` MINDEN
     | supervisorát MINDEN környezetbe beolvasztja (`array_replace_recursive($defaults, $env)`) — a
     | korábbi elrendezésben így a production (küldő, 2g) Horizon az import supervisorokat, az import-
     | konténer (`imports`, 3g) pedig a küldő / háttér / kampány-indító supervisorokat is elindította:
     | az izoláció csak a configban látszott. Most minden környezet PONTOSAN a saját supervisorait
     | sorolja fel, a közös definíciókból (`$supervisors`) — a teszt a `ProvisioningPlan` kimenetét nézi.
     */
    'defaults' => [],

    'environments' => [
        // KÜLÖN WORKER-KONTÉNER az importnak (`worker-imports`, `artisan horizon --environment=imports`): a
        // bájt-nehéz fájlok memóriaigénye nem ölheti ki a KÜLDŐ workert (közös cgroup-limit).
        'imports' => [
            'imports' => [...$supervisors['imports'], 'maxProcesses' => 2],
            'imports-inspect' => [...$supervisors['imports-inspect'], 'maxProcesses' => 2],
        ],
        // A KÜLDŐ worker: küldés, háttérmunka, kampány-materializálás — import NEM.
        'production' => [
            'sending' => [...$supervisors['sending'], 'maxProcesses' => 20, 'balanceMaxShift' => 3, 'balanceCooldown' => 3],
            'background' => [...$supervisors['background'], 'maxProcesses' => 6],
            'campaign-start' => [...$supervisors['campaign-start'], 'maxProcesses' => 2],
            'reports' => [...$supervisors['reports'], 'maxProcesses' => 1],
        ],
        // Fejlesztői és teszt-környezet: EGY Horizon mind az öt supervisorral.
        'local' => [
            'sending' => [...$supervisors['sending'], 'maxProcesses' => 3],
            'background' => [...$supervisors['background'], 'maxProcesses' => 2],
            'campaign-start' => [...$supervisors['campaign-start'], 'maxProcesses' => 1],
            'imports' => [...$supervisors['imports'], 'maxProcesses' => 1],
            'imports-inspect' => [...$supervisors['imports-inspect'], 'maxProcesses' => 1],
            'reports' => [...$supervisors['reports'], 'maxProcesses' => 1],
        ],
        'testing' => [
            'sending' => [...$supervisors['sending'], 'maxProcesses' => 1],
            'background' => [...$supervisors['background'], 'maxProcesses' => 1],
            'campaign-start' => [...$supervisors['campaign-start'], 'maxProcesses' => 1],
            'imports' => [...$supervisors['imports'], 'maxProcesses' => 1],
            'imports-inspect' => [...$supervisors['imports-inspect'], 'maxProcesses' => 1],
            'reports' => [...$supervisors['reports'], 'maxProcesses' => 1],
        ],
    ],
];
