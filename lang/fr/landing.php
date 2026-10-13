<?php

declare(strict_types=1);

/*
 | A STATIKUS TERMÉKOLDAL (apex) szövegei — FRANCIA. A kulcs-teljességet a LocaleCompletenessTest őrzi.
 */

return [
    'meta' => [
        'title' => 'ventMail — l\'infrastructure e-mail de la famille Venture',
        'description' => 'E-mail sortant et entrant sur un seul moteur : API transactionnelle et SMTP, Studio marketing, protections de délivrabilité intégrées. Exploité dans l\'UE.',
    ],
    'skip' => 'Aller au contenu',
    'nav' => [
        'login' => 'Se connecter',
    ],
    'hero' => [
        'eyebrow' => 'Infrastructure e-mail',
        'title' => 'L\'infrastructure e-mail de la famille Venture',
        'lede' => 'E-mail sortant ET entrant sur un seul moteur : une API transactionnelle et SMTP pour les développeurs, un Studio marketing pour les équipes.',
    ],
    'platform' => [
        'title' => 'Plateforme',
        'lede' => 'E-mail transactionnel et entrant pour les développeurs et les exploitants.',
        'items' => [
            'API de messages',
            'Relais SMTP',
            'Routage entrant',
            'Journaux et traçage des messages',
            'Validation d\'adresses',
            'Pools d\'IP et warmup',
            'Webhooks',
        ],
    ],
    'studio' => [
        'title' => 'Studio',
        'lede' => 'L\'e-mail marketing pour les équipes, sur le même moteur.',
        'items' => [
            'Audience et double opt-in',
            'Éditeur de blocs + MJML',
            'Campagnes et tests A/B',
            'Customer Journey',
            'Formulaires et centre de préférences',
            'Analytique marketing',
        ],
    ],
    'engine' => [
        'title' => 'Un moteur, deux côtés',
        'text' => 'Une campagne tourne sur le même moteur d\'envoi qu\'un appel d\'API : authentification de domaine, suppression et journaux partagés.',
    ],
    'deliver' => [
        'title' => 'Délivrabilité intégrée',
        'lede' => 'Pas une option mais une exigence : aucun envoi depuis un domaine non vérifié.',
        'items' => [
            ['title' => 'SPF, DKIM, DMARC obligatoires', 'text' => 'L\'envoi ne démarre que depuis un domaine vérifié.'],
            ['title' => 'Désabonnement en un clic', 'text' => 'Désabonnement RFC 8058 dans chaque e-mail marketing.'],
            ['title' => 'Pools d\'IP séparés', 'text' => 'Le trafic transactionnel et marketing utilise des pools distincts.'],
            ['title' => 'Suppression', 'text' => 'Aucun e-mail n\'est envoyé aux adresses en rebond ou désabonnées.'],
            ['title' => 'PreSendGate', 'text' => 'Chaque envoi, y compris via le relais SMTP, passe les six mêmes portes.'],
            ['title' => 'Webhooks signés', 'text' => 'Signatures HMAC-SHA256, nouvelles tentatives et rejeu pour chaque événement.'],
        ],
    ],
    'eu' => [
        'title' => 'Exploité dans l\'UE',
        'text' => 'Le service et ses données sont exploités au sein de l\'Union européenne.',
    ],
    'footer' => [
        'nav' => 'Informations légales',
        'terms' => 'Conditions d\'utilisation',
        'privacy' => 'Politique de confidentialité',
    ],
];
