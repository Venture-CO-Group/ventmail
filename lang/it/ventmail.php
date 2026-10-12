<?php

declare(strict_types=1);

/*
 | OLASZ.
 |
 | A KULCS-SZERKEZET az angol referenciával AZONOS (C2 · 89. kritérium). A hiányzó VAGY
 | extra kulcs TESZT-BUKÁS: a Laravel a hiányzó kulcs helyén a KULCSOT írja ki, vagy a
 | fallback nyelv szövegét — és egy angol mondat egy magyar felületen olyan hiba, amit
 | senki nem jelent be.
 */
return [
    'app' => [
        'name' => 'ventMail',
        'tagline' => 'Infrastruttura e-mail per la famiglia Venture',
        'workspace_switched' => 'Workspace cambiato.',
        'locale_switched' => 'Lingua aggiornata.',
    ],
    'apiKeys' => [
        'revoked' => 'Chiave revocata.',
    ],
    'smtp' => [
        'revoked' => 'Credenziale revocata.',
    ],
    'suppressions' => [
        'added' => 'Indirizzo aggiunto alla lista.',
        'removed' => 'Soppressione rimossa.',
        'missing' => 'Questo indirizzo non è nella lista.',
        'irrevocable' => 'Le soppressioni da reclamo e disiscrizione non si rimuovono: è la volontà del destinatario.',
    ],
    'webhooks' => [
        'deleted' => 'Endpoint eliminato.',
        'replayed' => 'Consegna rimessa in coda.',
        'alreadyDelivered' => 'Questa consegna è già riuscita.',
    ],
    'audience' => [
        'listCreated' => 'Lista creata.',
        'listUpdated' => 'Lista aggiornata.',
        'import' => [
            'reason' => [
                'invalid_email' => 'Indirizzo e-mail non valido.',
                'missing_email' => 'Manca l’indirizzo e-mail.',
                'invalid_field' => 'Il valore del campo “:field” non corrisponde al suo tipo.',
                'duplicate' => 'Duplicato: l’indirizzo era già presente alla riga :line.',
                'suppressed' => 'L’indirizzo è in una lista di soppressione.',
                'erased' => 'L’indirizzo è stato cancellato su richiesta GDPR e non può essere importato.',
                'revoked_consent' => 'L’indirizzo ha revocato il consenso.',
                'deleted_contact' => 'Il contatto è eliminato e non verrà riattivato.',
                'role_address' => 'Indirizzo di ruolo (es. info@), saltato.',
                'disposable_domain' => 'Dominio e-mail usa e getta, saltato.',
                'existing_contact' => 'Il contatto esiste già, i dati esistenti restano invariati.',
            ],
            'limit' => [
                'timeout' => 'L’analisi del file ha superato il tempo massimo — carica un file più piccolo.',
                'line' => 'Una riga del file è troppo lunga.',
                'size' => 'Il file è troppo grande.',
                'rows' => 'Il file ha più righe di quelle consentite.',
                'columns' => 'Il file ha più colonne di quelle consentite.',
                'zip' => 'Il file XLSX supera il limite di decompressione.',
                'format' => 'Il contenuto del file non corrisponde alla sua estensione.',
                'sheet' => 'Foglio sconosciuto.',
                'mapping' => 'La colonna e-mail non è associata.',
            ],
            'concurrency' => 'Ci sono già abbastanza importazioni in corso in questo workspace — attendi che una termini.',
            'fieldLimit' => 'Hai raggiunto il limite di campi personalizzati.',
            'pasteEmpty' => 'Incolla almeno un indirizzo e-mail.',
            'mappingUnknown' => 'Destinazione sconosciuta nell’associazione delle colonne.',
            'mappingDuplicate' => 'A un campo si può associare una sola colonna.',
            'mappingNoEmail' => 'Associa esattamente una colonna all’indirizzo e-mail.',
            'listUnknown' => 'Lista sconosciuta.',
            'fieldExists' => 'Esiste già un campo con questa chiave.',
            'fieldCreated' => 'Il campo personalizzato è stato creato.',
        ],
    ],
    'templates' => [
        'saved' => 'Modello salvato.',
    ],
    'campaigns' => [
        'saved' => 'Campagna salvata.',
        'schedule' => 'Campagna pianificata.',
        'unschedule' => 'Pianificazione annullata.',
        'start' => 'Campagna avviata.',
        'pause' => 'Campagna in pausa.',
        'resume' => 'Campagna ripresa.',
        'variantAdded' => 'Variante creata.',
        'variantLocked' => 'Nessuna nuova variante durante l’invio: la ripartizione è stata decisa all’avvio.',
        'variantRemoved' => 'Variante eliminata.',
        'variantMissing' => 'Variante inesistente — ricarica la pagina.',
    ],
    'invitations' => [
        'unavailable' => 'Questo invito non è più valido.',
        'accepted' => 'Invito accettato.',
        'declined' => 'Invito rifiutato.',
    ],
    'members' => [
        'notFound' => 'Iscrizione non trovata.',
        'rolesUpdated' => 'Ruoli aggiornati.',
        'invited' => 'Invito creato.',
        'invitationRevoked' => 'Invito revocato.',
        'removed' => 'Accesso revocato.',
        'subAccountCreated' => 'Sotto-account creato.',
        'notAllowed' => 'Non hai il permesso di gestire le iscrizioni.',
        'unknownRole' => 'Ruolo sconosciuto: :role',
        'noRoles' => 'Serve almeno un ruolo.',
        'strongerRole' => 'Non puoi assegnare un ruolo più forte del tuo (:role); permessi mancanti: :missing',
        'strongerTarget' => 'Non puoi modificare l’accesso di un membro più forte di te; permessi mancanti: :missing',
        'lastOwner' => 'L’ultimo proprietario del workspace non può essere rimosso né declassato.',
        'foreignMember' => 'Questa iscrizione appartiene a un altro workspace.',
        'selfRemoval' => 'Non puoi revocare la tua stessa iscrizione.',
        'invalidEmail' => 'Indirizzo e-mail non valido. L’invito richiede una parte locale in ASCII.',
        'alreadyMember' => 'Questo indirizzo è già membro del workspace.',
        'inviteConflict' => 'Una richiesta parallela ha appena invitato questo indirizzo — ricarica la pagina.',
        'subAccountNotAllowed' => 'Non hai il permesso di gestire i sotto-account.',
        'subAccountSingleLevel' => 'La gerarchia dei sotto-account è a un solo livello.',
        'subAccountLimit' => 'Hai raggiunto il limite di sotto-account (:max). Contatta il supporto se ne servono altri.',
        'mailSubject' => 'Sei stato invitato nel workspace :workspace',
        'mailIntro' => 'Sei stato invitato nel workspace ventMail :workspace. Accedi come al solito e l’accesso diventa effettivo:',
        'mailText' => "Sei stato invitato nel workspace ventMail :workspace.\n\nAccedi come al solito e l’accesso diventa effettivo:\n:url",
    ],
    'forms' => [
        'created' => 'Modulo creato.',
        'updated' => 'Modulo aggiornato.',
        'submitLabel' => 'Iscriviti',
    ],
    'storedTemplates' => [
        'activated' => 'La versione è ora attiva.',
        'unknownVersion' => 'Versione inesistente — ricarica la pagina.',
    ],
    'journeys' => [
        'activated' => 'Journey attiva.',
        'paused' => 'Journey in pausa.',
    ],
    'gate' => [
        'domain_unverified' => 'Il dominio di invio non è verificato.',
        'suppressed' => 'Il destinatario è nella lista di soppressione.',
        'no_consent' => 'Nessun consenso marketing confermato per questo destinatario.',
        'quota_exceeded' => 'La quota di invio è esaurita o l\'account è sospeso.',
        'rate_limited' => 'Limite di frequenza raggiunto; riprova a breve.',
        'validation_risk' => 'L\'indirizzo non ha superato i controlli di rischio.',
        'invalid_request' => 'La richiesta non rispetta le regole di invio (destinatario, canale o intestazione).',
        'gate_error' => 'Un controllo non ha potuto decidere; l\'invio è stato bloccato per sicurezza.',
        'rejected' => 'Il PreSendGate ha rifiutato l\'invio.',
    ],
    'campaign' => [
        'status' => [
            'draft' => 'Bozza',
            'scheduled' => 'Programmata',
            'sending' => 'Invio in corso',
            'paused' => 'In pausa',
            'sent' => 'Inviata',
            'canceled' => 'Annullata',
        ],
        'recipients' => 'Destinatari',
        'open_rate' => 'Tasso di apertura',
        'click_rate' => 'Tasso di clic',
    ],
    'unsubscribe' => [
        'heading' => 'Iscrizione annullata',
        'body' => 'Non riceverai più e-mail di marketing da questo mittente.',
        'preferences' => 'Gestisci le preferenze e-mail',
        'resubscribe' => 'Iscriviti di nuovo',
    ],
    'preferences' => [
        'heading' => 'Preferenze e-mail',
        'intro' => 'Scegli quali e-mail vuoi ricevere.',
        'save' => 'Salva preferenze',
        'saved' => 'Le tue preferenze sono state salvate.',
        'unsubscribe_all' => 'Annulla iscrizione a tutto',
    ],
    'consent' => [
        'confirm_heading' => 'Conferma la tua iscrizione',
        'confirm_body' => 'Conferma che desideri ricevere e-mail da questo mittente.',
        'confirmed' => 'Grazie, la tua iscrizione è confermata.',
        'expired' => 'Questo link di conferma è scaduto. Iscriviti di nuovo.',
    ],
    'platform' => [
        'impersonation_active' => 'Vista assistenza attiva — sola lettura.',
        'health' => [
            'healthy' => 'Integro',
            'degraded' => 'Degradato',
            'down' => 'Non disponibile',
            'unknown' => 'Sconosciuto',
        ],
        'impersonationEnded' => 'Vista di supporto chiusa.',
        'overrideGranted' => 'Override concesso.',
        'overrideRevoked' => 'Override revocato.',
        'suppressionAdded' => 'Soppressione globale aggiunta.',
        'suppressionRemoved' => 'Soppressione globale rimossa.',
    ],
    'auth' => [
        'retry' => 'Accesso non riuscito. Riprova.',
    ],
    'workspaces' => [
        'created' => 'Workspace «:name» creato.',
        'limit' => 'Hai raggiunto il limite di :max workspace di cui sei proprietario.',
    ],
    'profile' => [
        'saved' => 'Le tue impostazioni sono state salvate.',
    ],
    'editor' => [
        'nameTaken' => 'Esiste già un modello con questo nome.',
        'deleted' => 'Il modello è stato eliminato.',
        'blockDeleted' => 'Il blocco salvato è stato eliminato.',
        'assets' => [
            'tooLarge' => 'L’immagine è troppo grande (max :max MB).',
            'badType' => 'Si possono caricare solo immagini JPG, PNG, GIF o WebP.',
            'badDimensions' => 'L’immagine può essere al massimo di :max × :max pixel.',
        ],
        'compliance' => [
            'missing' => 'Un modello utilizzabile nelle campagne deve contenere un piè di pagina con: :items.',
            'items' => [
                'postal_address' => 'indirizzo postale',
                'unsubscribe' => 'link di disiscrizione',
                'preferences' => 'link alle preferenze',
            ],
        ],
        'testSend' => [
            'subjectPrefix' => '[TEST]',
            'notAllowed' => 'Le email di prova possono essere inviate solo ai membri del workspace o agli indirizzi seed.',
            'unverifiedDomain' => 'L’indirizzo del mittente deve usare un dominio verificato di questo workspace.',
            'empty' => 'Salva il modello prima di inviare una prova.',
        ],
    ],
];
