<?php

declare(strict_types=1);

/*
 | ventSSO OIDC kliens (Identity modul).
 |
 | A ventMail CSAK fogyasztó: nem bocsát ki tokent, nem kezel jelszót (golden rule 1).
 | A szerep a `resource_access.ventmail.roles` claimből jön — a TENANT-TAGSÁG NEM:
 | azt a saját `workspace_members` táblánk adja.
 |
 | Amíg a `ventmail` kliens nincs regisztrálva a ventSSO-ban, a provider a LOKÁLIS
 | ventsso példány (PHASE.md 2. döntés).
 */

return [
    'provider_url' => rtrim((string) env('OIDC_PROVIDER_URL', 'https://ventsso.app'), '/'),
    'client_id' => env('OIDC_CLIENT_ID', 'ventmail'),
    'client_secret' => env('OIDC_CLIENT_SECRET'),
    // A ventSSO v1.2 claim-katalógusában a ventMail csak a nevet és az e-mailt kéri; a
    // szerepek a hozzáférési tokenben/ID-tokenben a `resource_access` alatt jönnek.
    'scopes' => explode(' ', trim((string) env('OIDC_SCOPES', 'openid profile email'))),

    'roles_claim' => env('OIDC_ROLES_CLAIM', 'resource_access.ventmail.roles'),

    /*
     | A PLATFORM-ADMIN claim — SZÁNDÉKOSAN másik, mint a tenant-szerepek.
     |
     | A tenant-szerepek a `resource_access.ventmail.roles` alatt vannak. Ha a
     | platform-admin ugyanabból a listából jönne, egy rosszul konfigurált WORKSPACE-szerep
     | (valaki `platform_admin` nevű szerepet ad egy tenantnak) platform-hozzáférést adna
     | MINDEN tenant adatához. Két külön claim: két külön döntés a ventSSO oldalán.
     */
    'platform_admin_claim' => env('OIDC_PLATFORM_ADMIN_CLAIM', 'resource_access.ventmail-platform.roles'),
    'platform_admin_role' => env('OIDC_PLATFORM_ADMIN_ROLE', 'platform_admin'),

    // Elfogadott aláíró algoritmusok. Amit a JWKS hirdet, azt önmagában NEM fogadjuk el:
    // egy szimmetrikus (`oct`) kulcs HS256-konfúziót tenne lehetővé.
    'allowed_algorithms' => ['RS256', 'ES256'],

    // A discovery-végpontok https-kényszere. Kizárólag lokális fejlesztéshez oldható fel.
    'allow_insecure_transport' => (bool) env('OIDC_ALLOW_INSECURE', false),

    'redirect_path' => '/auth/callback',

    // Discovery + JWKS cache. Ismeretlen `kid` esetén a kliens EGYSZER ürít és újrapróbál.
    'discovery_cache_ttl' => (int) env('OIDC_DISCOVERY_CACHE_TTL', 3600),
    'jwks_cache_ttl' => (int) env('OIDC_JWKS_CACHE_TTL', 3600),

    // Az ID-token verifikáció megengedett óra-eltérése másodpercben.
    'leeway' => (int) env('OIDC_LEEWAY', 60),

    // A `state` / `nonce` / PKCE `code_verifier` élettartama a sessionben.
    'transient_ttl' => (int) env('OIDC_TRANSIENT_TTL', 600),
];
