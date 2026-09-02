<?php

declare(strict_types=1);

/*
 | Identity modul (ventSSO OIDC kliens) beállításai.
 */

return [
    /*
     | Fejlesztői belépés a flow-tesztekhez.
     |
     | KÜLÖN kapcsoló az APP_ENV mellé: a két őr különben UGYANABBÓL az egyetlen forrásból
     | döntene, tehát nem defense-in-depth lenne, hanem egy őr kétszer leírva. Az
     | `.env.example` alapértéke `local`, így aki a sablonból indul egy szerveren, alapból
     | nyitva hagyná — ezért itt az alapértelmezés FALSE.
     */
    'dev_login_enabled' => (bool) env('IDENTITY_DEV_LOGIN', false),
];
