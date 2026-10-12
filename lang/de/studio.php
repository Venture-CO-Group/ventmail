<?php

declare(strict_types=1);

// v1.1 · 5. modul (Studio): szegmens, címke, kampány-checklist, űrlap, preference center.
return [
    'segments' => [
        'saved' => 'Segment gespeichert.',
        'inUse' => 'Eine noch nicht versendete Kampagne nutzt dieses Segment — entferne es dort zuerst.',
        'deleted' => 'Segment gelöscht.',
        'invalid' => 'Ungültige Segmentdefinition.',
    ],
    'tags' => [
        'created' => 'Tag angelegt.',
        'renamed' => 'Tag umbenannt.',
        'merged' => ':count Kontakte wurden zum Tag „:name“ verschoben.',
        'usedByJourney' => 'Eine Journey nutzt diesen Tag (:names) — entferne ihn dort vor dem Zusammenführen.',
        'inUse' => 'Der Tag wird verwendet (:names) — entferne zuerst den Verweis oder führe ihn zusammen.',
        'deleted' => 'Tag gelöscht.',
        'bulkTagged' => ':count Kontakte haben den Tag „:name“ erhalten.',
        'bulkUntagged' => 'Der Tag „:name“ wurde von :count Kontakten entfernt.',
        'nameRequired' => 'Gib dem neuen Tag einen Namen.',
    ],
    'checklist' => [
        'notReady' => 'Die Kampagne kann noch nicht gesendet werden — es fehlt: :blockers.',
        'canceled' => 'Kampagne abgebrochen.',
    ],
];
