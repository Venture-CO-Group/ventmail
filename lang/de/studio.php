<?php

declare(strict_types=1);

// v1.1 · 5. modul (Studio): szegmens, címke, kampány-checklist, űrlap, preference center.
return [
    'segments' => [
        'saved' => 'Segment gespeichert.',
        'inUse' => 'Eine noch nicht versendete Kampagne nutzt dieses Segment — entferne es dort zuerst.',
        'deleted' => 'Segment gelöscht.',
        'invalid' => 'Ungültige Segmentdefinition.',
        'tooExpensive' => 'Das Segment ist zu aufwendig (es würde länger als 5 Sekunden laufen). Grenze die Bedingungen ein.',
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
        'unscheduled' => 'Nach der Änderung erfüllt die Kampagne die Checkliste nicht mehr; die Planung wurde zurückgenommen — korrigiere sie und plane erneut.',
    ],
    'hosted' => [
        'email' => 'E-Mail-Adresse',
        'firstName' => 'Vorname',
        'lastName' => 'Nachname',
        'trap' => 'Dieses Feld leer lassen',
        'subscribe' => 'Abonnieren',
        'doiNotice' => 'Nach der Anmeldung senden wir eine Bestätigungs-E-Mail — ohne Bestätigung senden wir nichts.',
        'thanksTitle' => 'Vielen Dank!',
        'thanksBody' => 'Wir haben dir eine Bestätigungs-E-Mail geschickt. Bitte klicke auf den Link darin.',
        'thanksNoMail' => 'Ohne Bestätigung senden wir nichts.',
        'confirmTitle' => 'Anmeldung bestätigen',
        'confirmBody' => 'Bestätige, dass du E-Mails von uns erhalten möchtest.',
        'confirmAction' => 'Ja, ich bestätige',
        'confirmIgnore' => 'Wenn du das nicht angefordert hast, schließe einfach diese Seite — ohne Bestätigung senden wir nichts.',
        'confirmedTitle' => 'Anmeldung bestätigt',
        'confirmedBody' => 'Danke! Deine Anmeldung ist aktiv.',
        'confirmedHint' => 'Du kannst deine Einstellungen jederzeit ändern; jede E-Mail enthält unten einen Abmeldelink.',
        'savedTitle' => 'Einstellungen gespeichert',
        'savedBody' => 'Deine Anfrage wurde verarbeitet.',
        'preferencesTitle' => 'E-Mail-Einstellungen',
        'preferencesInvalid' => 'Dieser Link ist nicht mehr gültig. Fordere über den Link unten in unserer E-Mail einen neuen an.',
        'preferencesLists' => 'Welche E-Mails möchtest du erhalten?',
        'preferencesProfile' => 'Deine Angaben',
        'preferencesSave' => 'Einstellungen speichern',
        'unsubscribeAll' => 'Von allen Marketing-E-Mails abmelden',
        'transactionalNote' => 'Rechnungen und Konto-Benachrichtigungen sind davon nicht betroffen.',
    ],
    'forms' => [
        'unknownField' => 'Unbekanntes Feld: :key. Nur Namensfelder und eigene Felder der Zielgruppe sind erlaubt.',
    ],
    'insights' => [
        'tooExpensive' => 'Die Zielgruppen-Statistik würde gerade zu lange dauern — versuche es später erneut.',
    ],
    'fields' => [
        'updated' => 'Feldeinstellung gespeichert.',
    ],
];
