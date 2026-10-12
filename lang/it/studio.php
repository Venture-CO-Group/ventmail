<?php

declare(strict_types=1);

// v1.1 · 5. modul (Studio): szegmens, címke, kampány-checklist, űrlap, preference center.
return [
    'segments' => [
        'saved' => 'Segmento salvato.',
        'inUse' => 'Una campagna non ancora inviata usa questo segmento — rimuovilo prima da lì.',
        'deleted' => 'Segmento eliminato.',
        'invalid' => 'Definizione del segmento non valida.',
    ],
    'tags' => [
        'created' => 'Etichetta creata.',
        'renamed' => 'Etichetta rinominata.',
        'merged' => ':count contatti spostati sull\'etichetta «:name».',
        'usedByJourney' => 'Un percorso usa questa etichetta (:names) — rimuovila da lì prima dell\'unione.',
        'inUse' => 'L\'etichetta è in uso (:names) — rimuovi prima il riferimento o uniscila.',
        'deleted' => 'Etichetta eliminata.',
        'bulkTagged' => ':count contatti hanno ricevuto l\'etichetta «:name».',
        'bulkUntagged' => 'L\'etichetta «:name» è stata rimossa da :count contatti.',
        'nameRequired' => 'Dai un nome alla nuova etichetta.',
    ],
    'checklist' => [
        'notReady' => 'La campagna non può ancora essere inviata — manca: :blockers.',
        'canceled' => 'Campagna annullata.',
    ],
    'hosted' => [
        'email' => 'Indirizzo email',
        'firstName' => 'Nome',
        'lastName' => 'Cognome',
        'trap' => 'Lascia vuoto questo campo',
        'subscribe' => 'Iscriviti',
        'doiNotice' => 'Dopo l\'iscrizione inviamo un\'email di conferma — senza conferma non inviamo nulla.',
        'thanksTitle' => 'Grazie!',
        'thanksBody' => 'Ti abbiamo inviato un\'email di conferma. Clicca sul link al suo interno.',
        'thanksNoMail' => 'Senza conferma non inviamo nulla.',
        'confirmTitle' => 'Conferma l\'iscrizione',
        'confirmBody' => 'Conferma che desideri ricevere le nostre email.',
        'confirmAction' => 'Sì, confermo',
        'confirmIgnore' => 'Se non lo hai richiesto, chiudi semplicemente questa pagina — senza conferma non inviamo nulla.',
        'confirmedTitle' => 'Iscrizione confermata',
        'confirmedBody' => 'Grazie! La tua iscrizione è attiva.',
        'confirmedHint' => 'Puoi modificare le preferenze in qualsiasi momento; ogni email contiene in fondo un link di disiscrizione.',
        'savedTitle' => 'Preferenze salvate',
        'savedBody' => 'La tua richiesta è stata elaborata.',
        'preferencesTitle' => 'Preferenze email',
        'preferencesInvalid' => 'Questo link non è più valido. Richiedine uno nuovo con il link in fondo alla nostra email.',
        'preferencesLists' => 'Quali email vuoi ricevere?',
        'preferencesProfile' => 'I tuoi dati',
        'preferencesSave' => 'Salva le preferenze',
        'unsubscribeAll' => 'Disiscrivimi da tutte le email di marketing',
        'transactionalNote' => 'Fatture e notifiche dell’account non sono interessate.',
    ],
    'forms' => [
        'unknownField' => 'Campo sconosciuto: :key. Sono ammessi solo i campi nome e i campi personalizzati del pubblico.',
    ],
];
