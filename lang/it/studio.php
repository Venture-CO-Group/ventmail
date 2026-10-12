<?php

declare(strict_types=1);

// v1.1 · 5. modul (Studio): szegmens, címke, kampány-checklist, űrlap, preference center.
return [
    'segments' => [
        'saved' => 'Segmento salvato.',
        'inUse' => 'Una campagna non ancora inviata usa questo segmento — rimuovilo prima da lì.',
        'deleted' => 'Segmento eliminato.',
        'invalid' => 'Definizione del segmento non valida.',
    ],
    'tags' => [
        'created' => 'Etichetta creata.',
        'renamed' => 'Etichetta rinominata.',
        'merged' => ':count contatti spostati sull\'etichetta «:name».',
        'usedByJourney' => 'Un percorso usa questa etichetta (:names) — rimuovila da lì prima dell\'unione.',
        'inUse' => 'L\'etichetta è in uso (:names) — rimuovi prima il riferimento o uniscila.',
        'deleted' => 'Etichetta eliminata.',
        'bulkTagged' => ':count contatti hanno ricevuto l\'etichetta «:name».',
        'bulkUntagged' => 'L\'etichetta «:name» è stata rimossa da :count contatti.',
        'nameRequired' => 'Dai un nome alla nuova etichetta.',
    ],
    'checklist' => [
        'notReady' => 'La campagna non può ancora essere inviata — manca: :blockers.',
        'canceled' => 'Campagna annullata.',
    ],
];
