<?php

declare(strict_types=1);

/*
 | A STATIKUS TERMÉKOLDAL (apex) szövegei. A kulcs-teljességet a LocaleCompletenessTest őrzi.
 */

return [
    'meta' => [
        'title' => 'ventMail — la infraestructura de correo de la familia Venture',
        'description' => 'Correo saliente y entrante en un solo motor: API transaccional y SMTP, Studio de marketing, protección de entregabilidad integrada. Operado en la UE.',
    ],
    'skip' => 'Saltar al contenido',
    'nav' => [
        'login' => 'Iniciar sesión',
        'docs' => 'Documentación de la API',
    ],
    'hero' => [
        'eyebrow' => 'Infraestructura de correo',
        'title' => 'La infraestructura de correo de la familia Venture',
        'lede' => 'Correo saliente Y entrante en un solo motor: API transaccional y SMTP para desarrolladores, Studio de marketing para equipos.',
    ],
    'platform' => [
        'title' => 'Platform',
        'lede' => 'Correo transaccional y entrante para desarrolladores y operadores.',
        'items' => [
            'Messages API',
            'SMTP relay',
            'Enrutamiento entrante (inbound)',
            'Registro y trace de mensajes',
            'Verificación de direcciones',
            'IP pools y warmup',
            'Webhooks',
        ],
    ],
    'studio' => [
        'title' => 'Studio',
        'lede' => 'Correo de marketing para equipos, en el mismo motor.',
        'items' => [
            'Audiencia y double opt-in',
            'Block editor + MJML',
            'Campañas y pruebas A/B',
            'Customer Journey',
            'Formularios y preference center',
            'Analítica de marketing',
        ],
    ],
    'engine' => [
        'title' => 'Un motor, dos lados',
        'text' => 'Una campaña se ejecuta en el mismo motor de envío que una llamada a la API: autenticación de dominio, supresión y registros compartidos.',
    ],
    'deliver' => [
        'title' => 'Entregabilidad de serie',
        'lede' => 'No es una opción, es un requisito: no se envía desde un dominio sin verificar.',
        'items' => [
            ['title' => 'SPF, DKIM, DMARC obligatorios', 'text' => 'El envío solo parte de un dominio verificado.'],
            ['title' => 'Baja con un clic', 'text' => 'Baja según RFC 8058 en cada correo de marketing.'],
            ['title' => 'IP pools separados', 'text' => 'El tráfico transaccional y el de marketing usan pools distintos.'],
            ['title' => 'Supresión', 'text' => 'No se envía correo a direcciones rebotadas o dadas de baja.'],
            ['title' => 'PreSendGate', 'text' => 'Cada envío, también por el SMTP relay, pasa por las mismas seis puertas.'],
            ['title' => 'Webhooks firmados', 'text' => 'Firma HMAC-SHA256, reintentos y reenvío para cada evento.'],
        ],
    ],
    'eu' => [
        'title' => 'Operado en la UE',
        'text' => 'El servicio y sus datos se operan en la Unión Europea.',
    ],
    'footer' => [
        'nav' => 'Información legal',
        'terms' => 'Términos de uso',
        'privacy' => 'Política de privacidad',
    ],
];
