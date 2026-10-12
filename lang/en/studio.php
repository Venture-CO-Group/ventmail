<?php

declare(strict_types=1);

// v1.1 · 5. modul (Studio): szegmens, címke, kampány-checklist, űrlap, preference center.
return [
    'segments' => [
        'saved' => 'Segment saved.',
        'inUse' => 'A campaign that has not been sent yet uses this segment — remove it there first.',
        'deleted' => 'Segment deleted.',
        'invalid' => 'Invalid segment definition.',
    ],
    'tags' => [
        'created' => 'Tag created.',
        'renamed' => 'Tag renamed.',
        'merged' => ':count contacts moved to the tag “:name”.',
        'usedByJourney' => 'A journey uses this tag (:names) — remove it there before merging.',
        'inUse' => 'The tag is in use (:names) — remove the reference or merge it first.',
        'deleted' => 'Tag deleted.',
        'bulkTagged' => ':count contacts received the tag “:name”.',
        'bulkUntagged' => 'The tag “:name” was removed from :count contacts.',
        'nameRequired' => 'Give the new tag a name.',
    ],
];
