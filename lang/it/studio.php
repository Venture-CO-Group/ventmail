<?php

declare(strict_types=1);

// v1.1 · 5. modul (Studio): szegmens, címke, kampány-checklist, űrlap, preference center.
return [
    'segments' => [
        'saved' => 'Segmento salvato.',
        'inUse' => 'Una campagna non ancora inviata usa questo segmento — rimuovilo prima da lì.',
        'deleted' => 'Segmento eliminato.',
        'invalid' => 'Definizione del segmento non valida.',
        'tooExpensive' => 'Il segmento è troppo costoso (durerebbe più di 5 secondi). Restringi le condizioni.',
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
        'unscheduled' => 'Dopo la modifica la campagna non supera più la checklist, quindi la pianificazione è stata annullata — correggila e ripianificala.',
        'unscheduledNoPublish' => 'La campagna è tornata in bozza: una campagna pianificata può essere modificata solo con il permesso di pubblicazione. Chi ha il permesso può ripianificarla.',
        'changed' => 'Nel frattempo lo stato della campagna è cambiato. Ricarica la pagina e riprova.',
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
    'insights' => [
        'tooExpensive' => 'Le statistiche del pubblico richiederebbero troppo tempo — riprova più tardi.',
    ],
    'fields' => [
        'updated' => 'Impostazione del campo salvata.',
    ],
    'templates' => [
        'publishLocked' => 'Questo modello è usato da una campagna o un journey attivo (:uses) — può essere modificato solo con il permesso di pubblicazione. Creane una copia o chiedi a chi ha il permesso.',
    ],
];
