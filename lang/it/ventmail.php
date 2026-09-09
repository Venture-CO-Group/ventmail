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
    ],
    'members' => [
        'notFound' => 'Iscrizione non trovata.',
        'rolesUpdated' => 'Ruoli aggiornati.',
        'invited' => 'Invito creato.',
        'invitationRevoked' => 'Invito revocato.',
        'removed' => 'Accesso revocato.',
        'subAccountCreated' => 'Sotto-account creato.',
    ],
    'forms' => [
        'created' => 'Modulo creato.',
        'updated' => 'Modulo aggiornato.',
        'submitLabel' => 'Iscriviti',
    ],
    'storedTemplates' => [
        'activated' => 'La versione è ora attiva.',
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
];
