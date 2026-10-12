<?php

declare(strict_types=1);

// v1.1 · 5. modul (Studio): szegmens, címke, kampány-checklist, űrlap, preference center.
return [
    'segments' => [
        'saved' => 'Segmento guardado.',
        'inUse' => 'Una campaña aún no enviada usa este segmento: quítalo de allí primero.',
        'deleted' => 'Segmento eliminado.',
        'invalid' => 'Definición de segmento no válida.',
    ],
    'tags' => [
        'created' => 'Etiqueta creada.',
        'renamed' => 'Etiqueta renombrada.',
        'merged' => ':count contactos se movieron a la etiqueta «:name».',
        'usedByJourney' => 'Un recorrido usa esta etiqueta (:names): quítala de allí antes de fusionar.',
        'inUse' => 'La etiqueta está en uso (:names): elimina primero la referencia o fusiónala.',
        'deleted' => 'Etiqueta eliminada.',
        'bulkTagged' => ':count contactos recibieron la etiqueta «:name».',
        'bulkUntagged' => 'Se quitó la etiqueta «:name» de :count contactos.',
        'nameRequired' => 'Ponle un nombre a la nueva etiqueta.',
    ],
];
