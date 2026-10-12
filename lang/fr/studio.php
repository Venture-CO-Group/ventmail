<?php

declare(strict_types=1);

// v1.1 · 5. modul (Studio): szegmens, címke, kampány-checklist, űrlap, preference center.
return [
    'segments' => [
        'saved' => 'Segment enregistré.',
        'inUse' => 'Une campagne pas encore envoyée utilise ce segment — retirez-le d\'abord de celle-ci.',
        'deleted' => 'Segment supprimé.',
        'invalid' => 'Définition de segment invalide.',
    ],
    'tags' => [
        'created' => 'Étiquette créée.',
        'renamed' => 'Étiquette renommée.',
        'merged' => ':count contacts ont été déplacés vers l\'étiquette « :name ».',
        'usedByJourney' => 'Un parcours utilise cette étiquette (:names) — retirez-la avant de fusionner.',
        'inUse' => 'L\'étiquette est utilisée (:names) — supprimez d\'abord la référence ou fusionnez-la.',
        'deleted' => 'Étiquette supprimée.',
        'bulkTagged' => ':count contacts ont reçu l\'étiquette « :name ».',
        'bulkUntagged' => 'L\'étiquette « :name » a été retirée de :count contacts.',
        'nameRequired' => 'Donnez un nom à la nouvelle étiquette.',
    ],
];
