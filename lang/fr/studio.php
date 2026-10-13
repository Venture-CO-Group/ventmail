<?php

declare(strict_types=1);

// v1.1 · 5. modul (Studio): szegmens, címke, kampány-checklist, űrlap, preference center.
return [
    'segments' => [
        'saved' => 'Segment enregistré.',
        'inUse' => 'Une campagne pas encore envoyée utilise ce segment — retirez-le d\'abord de celle-ci.',
        'deleted' => 'Segment supprimé.',
        'invalid' => 'Définition de segment invalide.',
        'tooExpensive' => 'Le segment est trop coûteux (il durerait plus de 5 secondes). Restreignez les conditions.',
    ],
    'tags' => [
        'created' => 'Étiquette créée.',
        'renamed' => 'Étiquette renommée.',
        'merged' => ':count contacts ont été déplacés vers l\'étiquette « :name ».',
        'usedByJourney' => 'Un parcours utilise cette étiquette (:names) — retirez-la avant de fusionner.',
        'inUse' => 'L\'étiquette est utilisée (:names) — supprimez d\'abord la référence ou fusionnez-la.',
        'deleted' => 'Étiquette supprimée.',
        'bulkTagged' => ':count contacts ont reçu l\'étiquette « :name ».',
        'bulkUntagged' => 'L\'étiquette « :name » a été retirée de :count contacts.',
        'nameRequired' => 'Donnez un nom à la nouvelle étiquette.',
    ],
    'checklist' => [
        'notReady' => 'La campagne ne peut pas encore être envoyée — il manque : :blockers.',
        'canceled' => 'Campagne annulée.',
        'unscheduled' => 'Après la modification, la campagne ne satisfait plus la checklist ; la planification a été annulée — corrigez-la puis replanifiez.',
        'unscheduledNoPublish' => 'La campagne est repassée en brouillon : une campagne planifiée ne peut être modifiée qu\'avec le droit de publication. Une personne autorisée peut la replanifier.',
    ],
    'hosted' => [
        'email' => 'Adresse e-mail',
        'firstName' => 'Prénom',
        'lastName' => 'Nom',
        'trap' => 'Laissez ce champ vide',
        'subscribe' => 'Je m\'abonne',
        'doiNotice' => 'Après l\'inscription, nous envoyons un e-mail de confirmation — rien n\'est envoyé sans votre confirmation.',
        'thanksTitle' => 'Merci !',
        'thanksBody' => 'Nous vous avons envoyé un e-mail de confirmation. Cliquez sur le lien qu’il contient.',
        'thanksNoMail' => 'Sans confirmation, nous n\'envoyons rien.',
        'confirmTitle' => 'Confirmer votre inscription',
        'confirmBody' => 'Confirmez que vous souhaitez recevoir nos e-mails.',
        'confirmAction' => 'Oui, je confirme',
        'confirmIgnore' => 'Si vous n\'êtes pas à l\'origine de cette demande, fermez simplement cette page — rien n\'est envoyé sans confirmation.',
        'confirmedTitle' => 'Inscription confirmée',
        'confirmedBody' => 'Merci ! Votre inscription est active.',
        'confirmedHint' => 'Vous pouvez modifier vos préférences à tout moment ; chaque e-mail contient un lien de désinscription en bas.',
        'savedTitle' => 'Préférences enregistrées',
        'savedBody' => 'Votre demande a été traitée.',
        'preferencesTitle' => 'Préférences e-mail',
        'preferencesInvalid' => 'Ce lien n\'est plus valide. Demandez-en un nouveau via le lien en bas de notre e-mail.',
        'preferencesLists' => 'Quels e-mails souhaitez-vous recevoir ?',
        'preferencesProfile' => 'Vos informations',
        'preferencesSave' => 'Enregistrer les préférences',
        'unsubscribeAll' => 'Me désinscrire de tous les e-mails marketing',
        'transactionalNote' => 'Les factures et les notifications de compte ne sont pas concernées.',
    ],
    'forms' => [
        'unknownField' => 'Champ inconnu : :key. Seuls les champs de nom et les champs personnalisés de l\'audience sont autorisés.',
    ],
    'insights' => [
        'tooExpensive' => 'Les statistiques d\'audience prendraient trop de temps — réessayez plus tard.',
    ],
    'fields' => [
        'updated' => 'Réglage du champ enregistré.',
    ],
];
