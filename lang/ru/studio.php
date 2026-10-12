<?php

declare(strict_types=1);

// v1.1 · 5. modul (Studio): szegmens, címke, kampány-checklist, űrlap, preference center.
return [
    'segments' => [
        'saved' => 'Сегмент сохранён.',
        'inUse' => 'Этот сегмент используется ещё не отправленной кампанией — сначала уберите его оттуда.',
        'deleted' => 'Сегмент удалён.',
        'invalid' => 'Некорректное определение сегмента.',
    ],
    'tags' => [
        'created' => 'Метка создана.',
        'renamed' => 'Метка переименована.',
        'merged' => ':count контактов перенесено в метку «:name».',
        'usedByJourney' => 'Эта метка используется сценарием (:names) — уберите её оттуда перед объединением.',
        'inUse' => 'Метка используется (:names) — сначала уберите ссылку или объедините метку.',
        'deleted' => 'Метка удалена.',
        'bulkTagged' => ':count контактов получили метку «:name».',
        'bulkUntagged' => 'Метка «:name» снята с :count контактов.',
        'nameRequired' => 'Укажите название новой метки.',
    ],
    'checklist' => [
        'notReady' => 'Кампанию ещё нельзя отправить — не хватает: :blockers.',
        'canceled' => 'Кампания отменена.',
    ],
];
