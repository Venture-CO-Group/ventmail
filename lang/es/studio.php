<?php

declare(strict_types=1);

// v1.1 · 5. modul (Studio): szegmens, címke, kampány-checklist, űrlap, preference center.
return [
    'segments' => [
        'saved' => 'Segmento guardado.',
        'inUse' => 'Una campaña aún no enviada usa este segmento: quítalo de allí primero.',
        'deleted' => 'Segmento eliminado.',
        'invalid' => 'Definición de segmento no válida.',
    ],
    'tags' => [
        'created' => 'Etiqueta creada.',
        'renamed' => 'Etiqueta renombrada.',
        'merged' => ':count contactos se movieron a la etiqueta «:name».',
        'usedByJourney' => 'Un recorrido usa esta etiqueta (:names): quítala de allí antes de fusionar.',
        'inUse' => 'La etiqueta está en uso (:names): elimina primero la referencia o fusiónala.',
        'deleted' => 'Etiqueta eliminada.',
        'bulkTagged' => ':count contactos recibieron la etiqueta «:name».',
        'bulkUntagged' => 'Se quitó la etiqueta «:name» de :count contactos.',
        'nameRequired' => 'Ponle un nombre a la nueva etiqueta.',
    ],
    'checklist' => [
        'notReady' => 'La campaña aún no puede enviarse; falta: :blockers.',
        'canceled' => 'Campaña cancelada.',
    ],
    'hosted' => [
        'email' => 'Correo electrónico',
        'firstName' => 'Nombre',
        'lastName' => 'Apellido',
        'trap' => 'Deja este campo vacío',
        'subscribe' => 'Suscribirme',
        'doiNotice' => 'Tras suscribirte te enviamos un correo de confirmación; sin confirmación no enviamos nada.',
        'thanksTitle' => '¡Gracias!',
        'thanksBody' => 'Te hemos enviado un correo de confirmación. Haz clic en el enlace que contiene.',
        'thanksNoMail' => 'Sin confirmación no enviamos nada.',
        'confirmTitle' => 'Confirma tu suscripción',
        'confirmBody' => 'Confirma que quieres recibir nuestros correos.',
        'confirmAction' => 'Sí, lo confirmo',
        'confirmIgnore' => 'Si no lo has pedido, cierra esta página; sin confirmación no enviamos nada.',
        'confirmedTitle' => 'Suscripción confirmada',
        'confirmedBody' => '¡Gracias! Tu suscripción está activa.',
        'confirmedHint' => 'Puedes cambiar tus preferencias cuando quieras; cada correo tiene un enlace de baja al final.',
        'savedTitle' => 'Preferencias guardadas',
        'savedBody' => 'Hemos procesado tu solicitud.',
        'preferencesTitle' => 'Preferencias de correo',
        'preferencesInvalid' => 'Este enlace ya no es válido. Pide uno nuevo con el enlace al final de nuestro correo.',
        'preferencesLists' => '¿Qué correos quieres recibir?',
        'preferencesProfile' => 'Tus datos',
        'preferencesSave' => 'Guardar preferencias',
        'unsubscribeAll' => 'Darme de baja de todos los correos de marketing',
        'transactionalNote' => 'Las facturas y las notificaciones de la cuenta no se ven afectadas.',
    ],
    'forms' => [
        'unknownField' => 'Campo desconocido: :key. Solo se admiten los campos de nombre y los campos personalizados de la audiencia.',
    ],
];
