<?php

declare(strict_types=1);

/*
 | FRANÇAIS — traduction complète des textes serveur (messages flash, portes, statuts).
 | La complétude des clés est gardée par `LocaleCompletenessTest` (référence : l'anglais).
 */
return [
    'app' => [
        'name' => 'ventMail',
        'tagline' => 'L\'infrastructure e-mail de la famille Venture',
        'workspace_switched' => 'Espace de travail changé.',
        'locale_switched' => 'Langue mise à jour.',
    ],

    'apiKeys' => [
        'revoked' => 'Clé révoquée.',
    ],
    'smtp' => [
        'revoked' => 'Identifiant révoqué.',
    ],
    'suppressions' => [
        'added' => 'Adresse ajoutée à la liste de suppression.',
        'removed' => 'Suppression retirée.',
        'missing' => 'Cette adresse ne figure pas dans la liste de suppression.',
        'irrevocable' => 'Les suppressions dues à une plainte ou à un désabonnement ne peuvent pas être retirées : c\'est la volonté du destinataire.',
    ],
    'webhooks' => [
        'deleted' => 'Point de terminaison supprimé.',
        'replayed' => 'Livraison remise en file d\'attente.',
        'alreadyDelivered' => 'Cette livraison a déjà réussi.',
    ],
    'audience' => [
        'listCreated' => 'Liste créée.',
        'listUpdated' => 'Liste mise à jour.',
    ],
    'templates' => [
        'saved' => 'Modèle enregistré.',
    ],
    'campaigns' => [
        'saved' => 'Campagne enregistrée.',
        'schedule' => 'Campagne programmée.',
        'unschedule' => 'Programmation supprimée.',
        'start' => 'Campagne démarrée.',
        'pause' => 'Campagne mise en pause.',
        'resume' => 'Campagne reprise.',
        'variantAdded' => 'Variante créée.',
        'variantLocked' => 'Pas de nouvelle variante pendant l\'envoi : la répartition a été décidée au début de l\'envoi.',
        'variantRemoved' => 'Variante supprimée.',
        'variantMissing' => 'Cette variante n\'existe pas — rechargez la page.',
    ],
    'invitations' => [
        'unavailable' => 'Cette invitation n\'est plus valide.',
        'accepted' => 'Invitation acceptée.',
        'declined' => 'Invitation refusée.',
    ],
    'members' => [
        'notFound' => 'Adhésion introuvable.',
        'rolesUpdated' => 'Rôles mis à jour.',
        'invited' => 'Invitation créée.',
        'invitationRevoked' => 'Invitation révoquée.',
        'removed' => 'Adhésion révoquée.',
        'subAccountCreated' => 'Sous-compte créé.',
        'notAllowed' => 'Vous n\'êtes pas autorisé à gérer les adhésions.',
        'unknownRole' => 'Rôle inconnu : :role',
        'noRoles' => 'Au moins un rôle est requis.',
        'strongerRole' => 'Vous ne pouvez pas accorder un rôle plus fort que le vôtre (:role) ; capacités qui vous manquent : :missing',
        'strongerTarget' => 'Vous ne pouvez pas modifier l\'accès d\'un membre plus fort que vous ; capacités qui vous manquent : :missing',
        'lastOwner' => 'Le dernier propriétaire de l\'espace de travail ne peut être ni retiré ni rétrogradé.',
        'foreignMember' => 'Cette adhésion appartient à un autre espace de travail.',
        'selfRemoval' => 'Vous ne pouvez pas révoquer votre propre adhésion.',
        'invalidEmail' => 'Adresse e-mail invalide. Une invitation exige une partie locale en ASCII simple.',
        'alreadyMember' => 'Cette adresse est déjà membre de cet espace de travail.',
        'inviteConflict' => 'Une requête simultanée vient d\'inviter cette adresse — rechargez la page.',
        'subAccountNotAllowed' => 'Vous n\'êtes pas autorisé à gérer les sous-comptes.',
        'subAccountSingleLevel' => 'La hiérarchie des sous-comptes n\'a qu\'un niveau : aucun ne peut être créé sous un sous-compte.',
        'subAccountLimit' => 'Vous avez atteint la limite de sous-comptes (:max). Contactez le support si vous en avez besoin de plus.',
        'mailSubject' => 'Vous avez été invité(e) à l\'espace de travail :workspace',
        'mailIntro' => 'Vous avez été invité(e) à l\'espace de travail ventMail :workspace. Connectez-vous comme d\'habitude et votre accès prend effet :',
        'mailText' => "Vous avez été invité(e) à l'espace de travail ventMail :workspace.\n\nConnectez-vous comme d'habitude et votre accès prend effet :\n:url",
    ],
    'forms' => [
        'created' => 'Formulaire créé.',
        'updated' => 'Formulaire mis à jour.',
        'submitLabel' => 'S\'abonner',
    ],
    'storedTemplates' => [
        'activated' => 'La version est désormais en ligne.',
        'unknownVersion' => 'Cette version n\'existe pas — rechargez la page.',
    ],
    'journeys' => [
        'activated' => 'Le parcours est actif.',
        'paused' => 'Parcours mis en pause.',
    ],
    'gate' => [
        'domain_unverified' => 'Le domaine d\'envoi n\'est pas vérifié.',
        'suppressed' => 'Le destinataire figure dans la liste de suppression.',
        'no_consent' => 'Aucun consentement marketing confirmé pour ce destinataire.',
        'quota_exceeded' => 'Le quota d\'envoi est épuisé ou le compte est suspendu.',
        'rate_limited' => 'Limite de débit atteinte ; réessayez dans un instant.',
        'validation_risk' => 'L\'adresse a échoué aux contrôles de risque de validation.',
        'invalid_request' => 'La requête ne respecte pas les règles d\'envoi (destinataire, canal ou en-tête).',
        'gate_error' => 'Un contrôle n\'a pas pu trancher ; l\'envoi a été suspendu par sécurité.',
        'rejected' => 'La PreSendGate a rejeté l\'envoi.',
    ],

    'campaign' => [
        'status' => [
            'draft' => 'Brouillon',
            'scheduled' => 'Programmée',
            'sending' => 'En cours d\'envoi',
            'paused' => 'En pause',
            'sent' => 'Envoyée',
            'canceled' => 'Annulée',
        ],
        'recipients' => 'Destinataires',
        'open_rate' => 'Taux d\'ouverture',
        'click_rate' => 'Taux de clic',
    ],

    'unsubscribe' => [
        'heading' => 'Vous avez été désabonné(e)',
        'body' => 'Vous ne recevrez plus d\'e-mails marketing de cet expéditeur.',
        'preferences' => 'Gérer vos préférences e-mail',
        'resubscribe' => 'S\'abonner de nouveau',
    ],

    'preferences' => [
        'heading' => 'Préférences e-mail',
        'intro' => 'Choisissez les e-mails que vous souhaitez recevoir.',
        'save' => 'Enregistrer les préférences',
        'saved' => 'Vos préférences ont été enregistrées.',
        'unsubscribe_all' => 'Se désabonner de tout',
    ],

    'consent' => [
        'confirm_heading' => 'Confirmez votre abonnement',
        'confirm_body' => 'Veuillez confirmer que vous souhaitez recevoir des e-mails de cet expéditeur.',
        'confirmed' => 'Merci, votre abonnement est confirmé.',
        'expired' => 'Ce lien de confirmation a expiré. Veuillez vous inscrire de nouveau.',
    ],

    'platform' => [
        'impersonation_active' => 'Vue support active — lecture seule.',
        'health' => [
            'healthy' => 'Sain',
            'degraded' => 'Dégradé',
            'down' => 'Hors service',
            'unknown' => 'Inconnu',
        ],
        'impersonationEnded' => 'Vue support fermée.',
        'overrideGranted' => 'Dérogation accordée.',
        'overrideRevoked' => 'Dérogation révoquée.',
        'suppressionAdded' => 'Suppression globale ajoutée.',
        'suppressionRemoved' => 'Suppression globale retirée.',
    ],
    'auth' => [
        'retry' => 'La connexion a échoué. Veuillez réessayer.',
    ],
    'workspaces' => [
        'created' => 'Espace de travail « :name » créé.',
        'limit' => 'Vous avez atteint la limite de :max espaces de travail dont vous êtes propriétaire.',
    ],
    'profile' => [
        'saved' => 'Vos préférences ont été enregistrées.',
    ],
];
