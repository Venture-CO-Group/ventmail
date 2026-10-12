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
    'audience' => [
        'listCreated' => 'Liste angelegt.',
        'listUpdated' => 'Liste aktualisiert.',
        'import' => [
            'reason' => [
                'invalid_email' => 'Ungültige E-Mail-Adresse.',
                'missing_email' => 'Die E-Mail-Adresse fehlt.',
                'invalid_field' => 'Der Wert des Felds „:field“ passt nicht zum Typ.',
                'duplicate' => 'Duplikat: Die Adresse stand bereits in Zeile :line.',
                'suppressed' => 'Die Adresse steht auf einer Sperrliste.',
                'erased' => 'Die Adresse wurde auf DSGVO-Anfrage gelöscht und kann nicht importiert werden.',
                'revoked_consent' => 'Die Adresse hat ihre Einwilligung widerrufen.',
                'deleted_contact' => 'Der Kontakt ist gelöscht und wird nicht wiederbelebt.',
                'role_address' => 'Funktionsadresse (z. B. info@), übersprungen.',
                'disposable_domain' => 'Wegwerf-E-Mail-Domain, übersprungen.',
                'existing_contact' => 'Der Kontakt existiert bereits, die vorhandenen Daten bleiben unverändert.',
            ],
            'limit' => [
                'timeout' => 'Die Prüfung der Datei hat das Zeitbudget überschritten — lade eine kleinere Datei hoch.',
                'line' => 'Eine Zeile in der Datei ist zu lang.',
                'size' => 'Die Datei ist zu groß.',
                'rows' => 'Die Datei hat mehr Zeilen als erlaubt.',
                'columns' => 'Die Datei hat mehr Spalten als erlaubt.',
                'zip' => 'Die XLSX-Datei überschreitet das Entpackungslimit.',
                'format' => 'Der Dateiinhalt passt nicht zur Dateiendung.',
                'sheet' => 'Unbekanntes Tabellenblatt.',
                'mapping' => 'Die E-Mail-Spalte ist nicht zugeordnet.',
            ],
            'concurrency' => 'In diesem Workspace laufen bereits genug Importe — warte, bis einer fertig ist.',
            'fieldLimit' => 'Du hast die Obergrenze für benutzerdefinierte Felder erreicht.',
            'pasteEmpty' => 'Füge mindestens eine E-Mail-Adresse ein.',
            'mappingUnknown' => 'Unbekanntes Ziel in der Spaltenzuordnung.',
            'mappingDuplicate' => 'Einem Feld kann nur eine Spalte zugeordnet werden.',
            'mappingNoEmail' => 'Ordne genau eine Spalte der E-Mail-Adresse zu.',
            'listUnknown' => 'Unbekannte Liste.',
            'fieldExists' => 'Ein Feld mit diesem Schlüssel existiert bereits.',
            'fieldCreated' => 'Das benutzerdefinierte Feld wurde angelegt.',
        ],
    ],
    'templates' => [
        'saved' => 'Vorlage gespeichert.',
    ],
    'campaigns' => [
        'saved' => 'Kampagne gespeichert.',
        'schedule' => 'Kampagne geplant.',
        'unschedule' => 'Planung aufgehoben.',
        'start' => 'Kampagne gestartet.',
        'pause' => 'Kampagne pausiert.',
        'resume' => 'Kampagne fortgesetzt.',
        'variantAdded' => 'Variante angelegt.',
        'variantLocked' => 'Während des Versands keine neue Variante: die Aufteilung wurde zu Sendebeginn festgelegt.',
        'variantRemoved' => 'Variante gelöscht.',
        'variantMissing' => 'Diese Variante existiert nicht — lade die Seite neu.',
    ],
    'invitations' => [
        'unavailable' => 'Diese Einladung ist nicht mehr gültig.',
        'accepted' => 'Einladung angenommen.',
        'declined' => 'Einladung abgelehnt.',
    ],
    'members' => [
        'notFound' => 'Mitgliedschaft nicht gefunden.',
        'rolesUpdated' => 'Rollen aktualisiert.',
        'invited' => 'Einladung erstellt.',
        'invitationRevoked' => 'Einladung widerrufen.',
        'removed' => 'Mitgliedschaft entzogen.',
        'subAccountCreated' => 'Unterkonto angelegt.',
        'notAllowed' => 'Du darfst die Mitgliedschaft nicht verwalten.',
        'unknownRole' => 'Unbekannte Rolle: :role',
        'noRoles' => 'Mindestens eine Rolle ist erforderlich.',
        'strongerRole' => 'Du kannst keine stärkere Rolle als deine eigene vergeben (:role); fehlende Rechte: :missing',
        'strongerTarget' => 'Du kannst den Zugriff eines stärkeren Mitglieds nicht ändern; fehlende Rechte: :missing',
        'lastOwner' => 'Der letzte Eigentümer des Workspace kann weder entfernt noch herabgestuft werden.',
        'foreignMember' => 'Diese Mitgliedschaft gehört zu einem anderen Workspace.',
        'selfRemoval' => 'Du kannst deine eigene Mitgliedschaft nicht entziehen.',
        'invalidEmail' => 'Ungültige E-Mail-Adresse. Eine Einladung braucht einen reinen ASCII-Lokalteil.',
        'alreadyMember' => 'Diese Adresse ist bereits Mitglied dieses Workspace.',
        'inviteConflict' => 'Eine parallele Anfrage hat diese Adresse gerade eingeladen — lade die Seite neu.',
        'subAccountNotAllowed' => 'Du darfst keine Unterkonten verwalten.',
        'subAccountSingleLevel' => 'Die Unterkonto-Hierarchie ist einstufig: unter einem Unterkonto entsteht kein weiteres.',
        'subAccountLimit' => 'Das Limit für Unterkonten ist erreicht (:max). Melde dich beim Support, wenn du mehr brauchst.',
        'mailSubject' => 'Du wurdest in den Workspace :workspace eingeladen',
        'mailIntro' => 'Du wurdest in den ventMail-Workspace :workspace eingeladen. Melde dich wie gewohnt an, dann greift dein Zugang:',
        'mailText' => "Du wurdest in den ventMail-Workspace :workspace eingeladen.\n\nMelde dich wie gewohnt an, dann greift dein Zugang:\n:url",
    ],
    'forms' => [
        'created' => 'Formular angelegt.',
        'updated' => 'Formular aktualisiert.',
        'submitLabel' => 'Abonnieren',
    ],
    'storedTemplates' => [
        'activated' => 'Die Version ist jetzt aktiv.',
        'unknownVersion' => 'Diese Version existiert nicht — lade die Seite neu.',
    ],
    'journeys' => [
        'activated' => 'Journey ist aktiv.',
        'paused' => 'Journey pausiert.',
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
        'impersonationEnded' => 'Support-Ansicht geschlossen.',
        'overrideGranted' => 'Override gewährt.',
        'overrideRevoked' => 'Override widerrufen.',
        'suppressionAdded' => 'Globale Sperre hinzugefügt.',
        'suppressionRemoved' => 'Globale Sperre aufgehoben.',
    ],
    'auth' => [
        'retry' => 'Die Anmeldung ist fehlgeschlagen. Bitte versuche es erneut.',
    ],
    'workspaces' => [
        'created' => 'Workspace „:name“ wurde erstellt.',
        'limit' => 'Du hast die Grenze von :max eigenen Workspaces erreicht.',
    ],
    'profile' => [
        'saved' => 'Deine Einstellungen wurden gespeichert.',
    ],
    'editor' => [
        'inUse' => 'Die Vorlage wird von einer laufenden Kampagne oder einer aktiven Journey verwendet: :names. Pausieren oder beenden Sie sie zuerst.',
        'nameTaken' => 'Eine Vorlage mit diesem Namen existiert bereits.',
        'deleted' => 'Die Vorlage wurde gelöscht.',
        'blocksFull' => 'Sie können höchstens :max gespeicherte Blöcke haben — löschen Sie zuerst einen.',
        'blockDeleted' => 'Der gespeicherte Block wurde gelöscht.',
        'assets' => [
            'tooLarge' => 'Das Bild ist zu groß (max. :max MB).',
            'badType' => 'Nur JPG-, PNG-, GIF- oder WebP-Bilder können hochgeladen werden.',
            'badDimensions' => 'Das Bild darf höchstens :max × :max Pixel groß sein.',
            'quota' => 'Der Bildspeicher des Workspaces ist voll (max. :max MB).',
            'processing' => 'Die Bildverarbeitung ist nicht verfügbar — der Upload wurde abgelehnt.',
        ],
        'compliance' => [
            'missing' => 'Eine kampagnenfähige Vorlage braucht eine Fußzeile mit: :items.',
            'items' => [
                'postal_address' => 'Postanschrift',
                'unsubscribe' => 'Abmeldelink',
                'preferences' => 'Link zu den Einstellungen',
            ],
        ],
        'testSend' => [
            'subjectPrefix' => '[TEST]',
            'notAllowed' => 'Test-E-Mails gehen nur an Workspace-Mitglieder oder Seed-Adressen.',
            'unverifiedDomain' => 'Die Absenderadresse muss eine verifizierte Domain dieses Workspaces verwenden.',
            'empty' => 'Speichern Sie die Vorlage vor dem Testversand.',
        ],
    ],
];
