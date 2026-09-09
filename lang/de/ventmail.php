<?php

declare(strict_types=1);

/*
 | NÉMET.
 |
 | A KULCS-SZERKEZET az angol referenciával AZONOS (C2 · 89. kritérium). A hiányzó VAGY
 | extra kulcs TESZT-BUKÁS: a Laravel a hiányzó kulcs helyén a KULCSOT írja ki, vagy a
 | fallback nyelv szövegét — és egy angol mondat egy magyar felületen olyan hiba, amit
 | senki nem jelent be.
 */
return [
    'app' => [
        'name' => 'ventMail',
        'tagline' => 'E-Mail-Infrastruktur für die Venture-Familie',
        'workspace_switched' => 'Workspace gewechselt.',
        'locale_switched' => 'Sprache aktualisiert.',
    ],
    'apiKeys' => [
        'revoked' => 'Schlüssel widerrufen.',
    ],
    'smtp' => [
        'revoked' => 'Zugangsdaten widerrufen.',
    ],
    'suppressions' => [
        'added' => 'Adresse zur Sperrliste hinzugefügt.',
        'removed' => 'Sperre aufgehoben.',
        'missing' => 'Diese Adresse steht nicht auf der Sperrliste.',
        'irrevocable' => 'Beschwerde- und Abmelde-Sperren lassen sich nicht aufheben: das ist der Wille des Empfängers.',
    ],
    'webhooks' => [
        'deleted' => 'Endpunkt gelöscht.',
        'replayed' => 'Zustellung erneut eingereiht.',
        'alreadyDelivered' => 'Diese Zustellung war bereits erfolgreich.',
    ],
    'gate' => [
        'domain_unverified' => 'Die Absenderdomain ist nicht verifiziert.',
        'suppressed' => 'Der Empfänger steht auf der Sperrliste.',
        'no_consent' => 'Keine bestätigte Marketing-Einwilligung für diesen Empfänger.',
        'quota_exceeded' => 'Das Sendekontingent ist erschöpft oder das Konto ist gesperrt.',
        'rate_limited' => 'Ratenlimit erreicht; bitte in Kürze erneut versuchen.',
        'validation_risk' => 'Die Adresse hat die Risikoprüfung nicht bestanden.',
        'invalid_request' => 'Die Anfrage erfüllt die Sendevorgaben nicht (Empfänger, Kanal oder Header).',
        'gate_error' => 'Eine Prüfung konnte nicht entscheiden; der Versand wurde sicherheitshalber unterbunden.',
        'rejected' => 'Das PreSendGate hat den Versand abgelehnt.',
    ],
    'campaign' => [
        'status' => [
            'draft' => 'Entwurf',
            'scheduled' => 'Geplant',
            'sending' => 'Wird gesendet',
            'paused' => 'Pausiert',
            'sent' => 'Gesendet',
            'canceled' => 'Abgebrochen',
        ],
        'recipients' => 'Empfänger',
        'open_rate' => 'Öffnungsrate',
        'click_rate' => 'Klickrate',
    ],
    'unsubscribe' => [
        'heading' => 'Sie wurden abgemeldet',
        'body' => 'Sie erhalten keine weiteren Marketing-E-Mails von diesem Absender.',
        'preferences' => 'E-Mail-Einstellungen verwalten',
        'resubscribe' => 'Erneut anmelden',
    ],
    'preferences' => [
        'heading' => 'E-Mail-Einstellungen',
        'intro' => 'Wählen Sie, welche E-Mails Sie erhalten möchten.',
        'save' => 'Einstellungen speichern',
        'saved' => 'Ihre Einstellungen wurden gespeichert.',
        'unsubscribe_all' => 'Von allem abmelden',
    ],
    'consent' => [
        'confirm_heading' => 'Bestätigen Sie Ihre Anmeldung',
        'confirm_body' => 'Bitte bestätigen Sie, dass Sie E-Mails von diesem Absender erhalten möchten.',
        'confirmed' => 'Vielen Dank, Ihre Anmeldung ist bestätigt.',
        'expired' => 'Dieser Bestätigungslink ist abgelaufen. Bitte melden Sie sich erneut an.',
    ],
    'platform' => [
        'impersonation_active' => 'Support-Ansicht aktiv — nur Lesen.',
        'health' => [
            'healthy' => 'In Ordnung',
            'degraded' => 'Beeinträchtigt',
            'down' => 'Ausgefallen',
            'unknown' => 'Unbekannt',
        ],
    ],
];
