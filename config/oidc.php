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
    'scopes' => explode(' ', trim((string) env('OIDC_SCOPES', 'openid profile email roles'))),

    'roles_claim' => env('OIDC_ROLES_CLAIM', 'resource_access.ventmail.roles'),

    'redirect_path' => '/auth/callback',

    // Discovery + JWKS cache. Ismeretlen `kid` esetén a kliens EGYSZER ürít és újrapróbál.
    'discovery_cache_ttl' => (int) env('OIDC_DISCOVERY_CACHE_TTL', 3600),
    'jwks_cache_ttl' => (int) env('OIDC_JWKS_CACHE_TTL', 3600),

    // Az ID-token verifikáció megengedett óra-eltérése másodpercben.
    'leeway' => (int) env('OIDC_LEEWAY', 60),

    // A `state` / `nonce` / PKCE `code_verifier` élettartama a sessionben.
    'transient_ttl' => (int) env('OIDC_TRANSIENT_TTL', 600),
];
