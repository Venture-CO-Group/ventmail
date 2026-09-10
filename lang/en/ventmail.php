<?php

declare(strict_types=1);

/*
 | ANGOL — a REFERENCIA-nyelv (C2 · 89. kritérium).
 |
 | ## Miért az angol a referencia, ha a termék magyar cégé
 |
 | Mert a `fallback_locale` az angol, és a kulcs-teljességi tesztnek EGY forrást kell
 | tekintenie igazságnak. Ha a magyar lenne a referencia, egy magyarul hozzáadott kulcs
 | öt nyelven bukna el a teszten — ami helyes —, de egy angolul hozzáadott kulcs
 | CSENDBEN átmenne, és a magyar felületen angol szöveg jelenne meg.
 |
 | ## A HIÁNYZÓ KULCS TESZT-BUKÁS, nem futásidejű fallback
 |
 | A Laravel a hiányzó kulcs helyén a KULCSOT ÍRJA KI (`ventmail.campaign.sent`), vagy a
 | fallback nyelv szövegét. Mindkettő rosszabb, mint a hiba: egy magyar felhasználó vagy
 | egy pontot tartalmazó technikai azonosítót lát, vagy angol szöveget — és EZT SENKI NEM
 | JELENTI BE. A `LocaleCompletenessTest` ezért a hiányzó ÉS az extra kulcsot is bukásnak
 | tekinti.
 */
return [
    'app' => [
        'name' => 'ventMail',
        'tagline' => 'Email infrastructure for the Venture family',
        'workspace_switched' => 'Workspace switched.',
        'locale_switched' => 'Language updated.',
    ],

    'apiKeys' => [
        'revoked' => 'Key revoked.',
    ],
    'smtp' => [
        'revoked' => 'Credential revoked.',
    ],
    'suppressions' => [
        'added' => 'Address added to the suppression list.',
        'removed' => 'Suppression removed.',
        'missing' => 'This address is not on the suppression list.',
        'irrevocable' => 'Complaint- and unsubscribe-based suppressions cannot be removed: that is the recipient\'s will.',
    ],
    'webhooks' => [
        'deleted' => 'Endpoint deleted.',
        'replayed' => 'Delivery re-queued.',
        'alreadyDelivered' => 'This delivery already succeeded.',
    ],
    'audience' => [
        'listCreated' => 'List created.',
        'listUpdated' => 'List updated.',
    ],
    'templates' => [
        'saved' => 'Template saved.',
    ],
    'campaigns' => [
        'saved' => 'Campaign saved.',
        'schedule' => 'Campaign scheduled.',
        'unschedule' => 'Schedule removed.',
        'start' => 'Campaign started.',
        'pause' => 'Campaign paused.',
        'resume' => 'Campaign resumed.',
        'variantAdded' => 'Variant created.',
        'variantLocked' => 'No new variant while sending: the split was decided when sending started.',
        'variantRemoved' => 'Variant deleted.',
        'variantMissing' => 'No such variant — reload the page.',
    ],
    'invitations' => [
        'unavailable' => 'This invitation is no longer valid.',
        'accepted' => 'Invitation accepted.',
        'declined' => 'Invitation declined.',
    ],
    'members' => [
        'notFound' => 'Membership not found.',
        'rolesUpdated' => 'Roles updated.',
        'invited' => 'Invitation created.',
        'invitationRevoked' => 'Invitation revoked.',
        'removed' => 'Membership revoked.',
        'subAccountCreated' => 'Sub-account created.',
        'notAllowed' => 'You are not allowed to manage membership.',
        'unknownRole' => 'Unknown role: :role',
        'noRoles' => 'At least one role is required.',
        'strongerRole' => 'You cannot grant a role stronger than your own (:role); abilities you lack: :missing',
        'strongerTarget' => 'You cannot change the access of a member stronger than you; abilities you lack: :missing',
        'lastOwner' => 'The last owner of the workspace cannot be removed or demoted.',
        'foreignMember' => 'That membership belongs to another workspace.',
        'selfRemoval' => 'You cannot revoke your own membership.',
        'invalidEmail' => 'Invalid e-mail address. An invitation needs a plain ASCII local part.',
        'alreadyMember' => 'That address is already a member of this workspace.',
        'inviteConflict' => 'A concurrent request just invited this address — reload the page.',
        'subAccountNotAllowed' => 'You are not allowed to manage sub-accounts.',
        'subAccountSingleLevel' => 'The sub-account hierarchy is single-level: none can be created below a sub-account.',
        'subAccountLimit' => 'You have reached the sub-account limit (:max). Contact support if you need more.',
        'mailSubject' => 'You have been invited to the :workspace workspace',
        'mailIntro' => 'You have been invited to the :workspace ventMail workspace. Sign in as usual and your access takes effect:',
        'mailText' => "You have been invited to the :workspace ventMail workspace.\n\nSign in as usual and your access takes effect:\n:url",
    ],
    'forms' => [
        'created' => 'Form created.',
        'updated' => 'Form updated.',
        'submitLabel' => 'Subscribe',
    ],
    'storedTemplates' => [
        'activated' => 'The version is now live.',
        'unknownVersion' => 'No such version — reload the page.',
    ],
    'journeys' => [
        'activated' => 'Journey is active.',
        'paused' => 'Journey paused.',
    ],
    'gate' => [
        'domain_unverified' => 'The sending domain is not verified.',
        'suppressed' => 'The recipient is on the suppression list.',
        'no_consent' => 'No confirmed marketing consent for this recipient.',
        'quota_exceeded' => 'The sending quota is exhausted or the account is suspended.',
        'rate_limited' => 'Rate limit reached; try again shortly.',
        'validation_risk' => 'The address failed validation risk checks.',
        'invalid_request' => 'The request does not satisfy the sending rules (recipient, channel or header).',
        'gate_error' => 'A check could not decide; the send was withheld for safety.',
        'rejected' => 'The PreSendGate rejected the send.',
    ],

    'campaign' => [
        'status' => [
            'draft' => 'Draft',
            'scheduled' => 'Scheduled',
            'sending' => 'Sending',
            'paused' => 'Paused',
            'sent' => 'Sent',
            'canceled' => 'Canceled',
        ],
        'recipients' => 'Recipients',
        'open_rate' => 'Open rate',
        'click_rate' => 'Click rate',
    ],

    'unsubscribe' => [
        'heading' => 'You have been unsubscribed',
        'body' => 'You will no longer receive marketing emails from this sender.',
        'preferences' => 'Manage your email preferences',
        'resubscribe' => 'Subscribe again',
    ],

    'preferences' => [
        'heading' => 'Email preferences',
        'intro' => 'Choose which emails you want to receive.',
        'save' => 'Save preferences',
        'saved' => 'Your preferences have been saved.',
        'unsubscribe_all' => 'Unsubscribe from everything',
    ],

    'consent' => [
        'confirm_heading' => 'Confirm your subscription',
        'confirm_body' => 'Please confirm that you want to receive emails from this sender.',
        'confirmed' => 'Thank you, your subscription is confirmed.',
        'expired' => 'This confirmation link has expired. Please sign up again.',
    ],

    'platform' => [
        'impersonation_active' => 'Support view active — read only.',
        'health' => [
            'healthy' => 'Healthy',
            'degraded' => 'Degraded',
            'down' => 'Down',
            'unknown' => 'Unknown',
        ],
        'impersonationEnded' => 'Support view closed.',
        'overrideGranted' => 'Override granted.',
        'overrideRevoked' => 'Override revoked.',
        'suppressionAdded' => 'Global suppression added.',
        'suppressionRemoved' => 'Global suppression removed.',
    ],
];
