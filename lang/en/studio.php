<?php

declare(strict_types=1);

// v1.1 · 5. modul (Studio): szegmens, címke, kampány-checklist, űrlap, preference center.
return [
    'segments' => [
        'saved' => 'Segment saved.',
        'inUse' => 'A campaign that has not been sent yet uses this segment — remove it there first.',
        'deleted' => 'Segment deleted.',
        'invalid' => 'Invalid segment definition.',
        'tooExpensive' => 'The segment is too expensive (it would run longer than 5 seconds). Narrow the conditions.',
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
    'checklist' => [
        'notReady' => 'The campaign cannot be sent yet — missing: :blockers.',
        'canceled' => 'Campaign canceled.',
        'unscheduled' => 'After the change the campaign no longer passes the checklist, so it was unscheduled — fix it and schedule again.',
        'unscheduledNoPublish' => 'The campaign was moved back to draft: a scheduled campaign can only be changed with publish rights. A publisher can schedule it again.',
        'changed' => 'The campaign\'s status changed in the meantime. Reload the page and try again.',
    ],
    'hosted' => [
        'email' => 'Email address',
        'firstName' => 'First name',
        'lastName' => 'Last name',
        'trap' => 'Leave this field empty',
        'subscribe' => 'Subscribe',
        'doiNotice' => 'After signing up we send a confirmation email — nothing is sent without your confirmation.',
        'thanksTitle' => 'Thank you!',
        'thanksBody' => 'We have sent you a confirmation email. Please click the link inside.',
        'thanksNoMail' => 'Without confirmation we send nothing.',
        'confirmTitle' => 'Confirm your subscription',
        'confirmBody' => 'Confirm that you would like to receive emails from us.',
        'confirmAction' => 'Yes, I confirm',
        'confirmIgnore' => 'If you did not ask for this, just close this page — nothing is sent without confirmation.',
        'confirmedTitle' => 'Subscription confirmed',
        'confirmedBody' => 'Thank you! Your subscription is active.',
        'confirmedHint' => 'You can change your preferences at any time, and every email has an unsubscribe link at the bottom.',
        'savedTitle' => 'Preferences saved',
        'savedBody' => 'Your request has been processed.',
        'preferencesTitle' => 'Email preferences',
        'preferencesInvalid' => 'This link is no longer valid. Request a new one with the link at the bottom of our email.',
        'preferencesLists' => 'Which emails would you like to receive?',
        'preferencesProfile' => 'Your details',
        'preferencesSave' => 'Save preferences',
        'unsubscribeAll' => 'Unsubscribe from all marketing emails',
        'transactionalNote' => 'Invoices and account notifications are not affected.',
    ],
    'forms' => [
        'unknownField' => 'Unknown field: :key. Only the name fields and the audience custom fields can be used.',
    ],
    'insights' => [
        'tooExpensive' => 'Audience statistics would take too long right now — try again later.',
    ],
    'fields' => [
        'updated' => 'Field setting saved.',
    ],
    'templates' => [
        'publishLocked' => 'This template is used by an active campaign or journey (:uses) — it can only be changed with publish rights. Make a copy, or ask a publisher.',
        'assetInSavedBlock' => 'This image is used by a saved block — it can only be deleted with publish rights.',
    ],
];
