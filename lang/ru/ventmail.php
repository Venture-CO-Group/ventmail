<?php

declare(strict_types=1);

/*
 | OROSZ.
 |
 | A KULCS-SZERKEZET az angol referenciával AZONOS (C2 · 89. kritérium). A hiányzó VAGY
 | extra kulcs TESZT-BUKÁS: a Laravel a hiányzó kulcs helyén a KULCSOT írja ki, vagy a
 | fallback nyelv szövegét — és egy angol mondat egy magyar felületen olyan hiba, amit
 | senki nem jelent be.
 */
return [
    'app' => [
        'name' => 'ventMail',
        'tagline' => 'Почтовая инфраструктура для семейства Venture',
    ],
    'gate' => [
        'domain_unverified' => 'Домен отправителя не подтверждён.',
        'suppressed' => 'Получатель находится в списке блокировки.',
        'no_consent' => 'Нет подтверждённого согласия на маркетинговые письма.',
        'quota_exceeded' => 'Квота отправки исчерпана или аккаунт приостановлен.',
        'rate_limited' => 'Достигнут предел частоты; повторите попытку позже.',
        'validation_risk' => 'Адрес не прошёл проверку рисков.',
        'invalid_request' => 'Запрос не соответствует правилам отправки (получатель, канал или заголовок).',
        'gate_error' => 'Проверка не смогла принять решение; отправка остановлена из осторожности.',
        'rejected' => 'PreSendGate отклонил отправку.',
    ],
    'campaign' => [
        'status' => [
            'draft' => 'Черновик',
            'scheduled' => 'Запланирована',
            'sending' => 'Отправляется',
            'paused' => 'Приостановлена',
            'sent' => 'Отправлена',
            'canceled' => 'Отменена',
        ],
        'recipients' => 'Получатели',
        'open_rate' => 'Процент открытий',
        'click_rate' => 'Процент переходов',
    ],
    'unsubscribe' => [
        'heading' => 'Вы отписались',
        'body' => 'Вы больше не будете получать маркетинговые письма от этого отправителя.',
        'preferences' => 'Управление настройками писем',
        'resubscribe' => 'Подписаться снова',
    ],
    'preferences' => [
        'heading' => 'Настройки писем',
        'intro' => 'Выберите, какие письма вы хотите получать.',
        'save' => 'Сохранить настройки',
        'saved' => 'Ваши настройки сохранены.',
        'unsubscribe_all' => 'Отписаться от всего',
    ],
    'consent' => [
        'confirm_heading' => 'Подтвердите подписку',
        'confirm_body' => 'Подтвердите, что хотите получать письма от этого отправителя.',
        'confirmed' => 'Спасибо, ваша подписка подтверждена.',
        'expired' => 'Срок действия ссылки истёк. Пожалуйста, подпишитесь снова.',
    ],
    'platform' => [
        'impersonation_active' => 'Режим поддержки активен — только чтение.',
        'health' => [
            'healthy' => 'В порядке',
            'degraded' => 'Ухудшено',
            'down' => 'Недоступно',
            'unknown' => 'Неизвестно',
        ],
    ],
];
