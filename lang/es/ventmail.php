<?php

declare(strict_types=1);

/*
 | SPANYOL.
 |
 | A KULCS-SZERKEZET az angol referenciával AZONOS (C2 · 89. kritérium). A hiányzó VAGY
 | extra kulcs TESZT-BUKÁS: a Laravel a hiányzó kulcs helyén a KULCSOT írja ki, vagy a
 | fallback nyelv szövegét — és egy angol mondat egy magyar felületen olyan hiba, amit
 | senki nem jelent be.
 */
return [
    'app' => [
        'name' => 'ventMail',
        'tagline' => 'Infraestructura de correo para la familia Venture',
        'workspace_switched' => 'Workspace cambiado.',
        'locale_switched' => 'Idioma actualizado.',
    ],
    'apiKeys' => [
        'revoked' => 'Clave revocada.',
    ],
    'smtp' => [
        'revoked' => 'Credencial revocada.',
    ],
    'suppressions' => [
        'added' => 'Dirección añadida a la lista.',
        'removed' => 'Supresión eliminada.',
        'missing' => 'Esta dirección no está en la lista.',
        'irrevocable' => 'Las supresiones por queja o baja no se pueden quitar: es la voluntad del destinatario.',
    ],
    'webhooks' => [
        'deleted' => 'Endpoint eliminado.',
        'replayed' => 'Entrega reencolada.',
        'alreadyDelivered' => 'Esta entrega ya tuvo éxito.',
    ],
    'audience' => [
        'listCreated' => 'Lista creada.',
        'listUpdated' => 'Lista actualizada.',
    ],
    'templates' => [
        'saved' => 'Plantilla guardada.',
    ],
    'campaigns' => [
        'saved' => 'Campaña guardada.',
        'schedule' => 'Campaña programada.',
        'unschedule' => 'Programación cancelada.',
        'start' => 'Campaña iniciada.',
        'pause' => 'Campaña pausada.',
        'resume' => 'Campaña reanudada.',
        'variantAdded' => 'Variante creada.',
        'variantLocked' => 'No se añaden variantes durante el envío: el reparto se decidió al empezar.',
        'variantRemoved' => 'Variante eliminada.',
        'variantMissing' => 'No existe esa variante — recarga la página.',
    ],
    'invitations' => [
        'unavailable' => 'Esta invitación ya no es válida.',
        'accepted' => 'Invitación aceptada.',
        'declined' => 'Invitación rechazada.',
    ],
    'members' => [
        'notFound' => 'No se encuentra la membresía.',
        'rolesUpdated' => 'Roles actualizados.',
        'invited' => 'Invitación creada.',
        'invitationRevoked' => 'Invitación revocada.',
        'removed' => 'Acceso revocado.',
        'subAccountCreated' => 'Subcuenta creada.',
        'notAllowed' => 'No tienes permiso para gestionar la membresía.',
        'unknownRole' => 'Rol desconocido: :role',
        'noRoles' => 'Se necesita al menos un rol.',
        'strongerRole' => 'No puedes asignar un rol más fuerte que el tuyo (:role); permisos que te faltan: :missing',
        'strongerTarget' => 'No puedes cambiar el acceso de un miembro más fuerte que tú; permisos que te faltan: :missing',
        'lastOwner' => 'El último propietario del workspace no puede eliminarse ni degradarse.',
        'foreignMember' => 'Esa membresía pertenece a otro workspace.',
        'selfRemoval' => 'No puedes revocar tu propia membresía.',
        'invalidEmail' => 'Dirección de correo no válida. La invitación necesita una parte local en ASCII.',
        'alreadyMember' => 'Esa dirección ya es miembro del workspace.',
        'inviteConflict' => 'Una petición paralela acaba de invitar esta dirección — recarga la página.',
        'subAccountNotAllowed' => 'No tienes permiso para gestionar subcuentas.',
        'subAccountSingleLevel' => 'La jerarquía de subcuentas tiene un solo nivel.',
        'subAccountLimit' => 'Has alcanzado el límite de subcuentas (:max). Contacta con soporte si necesitas más.',
        'mailSubject' => 'Te han invitado al workspace :workspace',
        'mailIntro' => 'Te han invitado al workspace de ventMail :workspace. Inicia sesión como siempre y tu acceso se activa:',
        'mailText' => "Te han invitado al workspace de ventMail :workspace.\n\nInicia sesión como siempre y tu acceso se activa:\n:url",
    ],
    'forms' => [
        'created' => 'Formulario creado.',
        'updated' => 'Formulario actualizado.',
        'submitLabel' => 'Suscribirme',
    ],
    'storedTemplates' => [
        'activated' => 'La versión ya está activa.',
        'unknownVersion' => 'No existe esa versión — recarga la página.',
    ],
    'journeys' => [
        'activated' => 'Journey activa.',
        'paused' => 'Journey pausada.',
    ],
    'gate' => [
        'domain_unverified' => 'El dominio de envío no está verificado.',
        'suppressed' => 'El destinatario está en la lista de supresión.',
        'no_consent' => 'No hay consentimiento de marketing confirmado para este destinatario.',
        'quota_exceeded' => 'La cuota de envío está agotada o la cuenta está suspendida.',
        'rate_limited' => 'Límite de frecuencia alcanzado; inténtalo de nuevo en breve.',
        'validation_risk' => 'La dirección no superó las comprobaciones de riesgo.',
        'invalid_request' => 'La solicitud no cumple las reglas de envío (destinatario, canal o encabezado).',
        'gate_error' => 'Una comprobación no pudo decidir; el envío se detuvo por seguridad.',
        'rejected' => 'El PreSendGate rechazó el envío.',
    ],
    'campaign' => [
        'status' => [
            'draft' => 'Borrador',
            'scheduled' => 'Programada',
            'sending' => 'Enviando',
            'paused' => 'En pausa',
            'sent' => 'Enviada',
            'canceled' => 'Cancelada',
        ],
        'recipients' => 'Destinatarios',
        'open_rate' => 'Tasa de apertura',
        'click_rate' => 'Tasa de clics',
    ],
    'unsubscribe' => [
        'heading' => 'Te has dado de baja',
        'body' => 'No recibirás más correos de marketing de este remitente.',
        'preferences' => 'Gestionar preferencias de correo',
        'resubscribe' => 'Volver a suscribirse',
    ],
    'preferences' => [
        'heading' => 'Preferencias de correo',
        'intro' => 'Elige qué correos quieres recibir.',
        'save' => 'Guardar preferencias',
        'saved' => 'Tus preferencias se han guardado.',
        'unsubscribe_all' => 'Darse de baja de todo',
    ],
    'consent' => [
        'confirm_heading' => 'Confirma tu suscripción',
        'confirm_body' => 'Confirma que quieres recibir correos de este remitente.',
        'confirmed' => 'Gracias, tu suscripción está confirmada.',
        'expired' => 'Este enlace de confirmación ha caducado. Suscríbete de nuevo.',
    ],
    'platform' => [
        'impersonation_active' => 'Vista de soporte activa — solo lectura.',
        'health' => [
            'healthy' => 'Correcto',
            'degraded' => 'Degradado',
            'down' => 'Caído',
            'unknown' => 'Desconocido',
        ],
        'impersonationEnded' => 'Vista de soporte cerrada.',
        'overrideGranted' => 'Override concedido.',
        'overrideRevoked' => 'Override revocado.',
        'suppressionAdded' => 'Supresión global añadida.',
        'suppressionRemoved' => 'Supresión global eliminada.',
    ],
    'auth' => [
        'retry' => 'No se pudo iniciar sesión. Inténtalo de nuevo.',
    ],
];
