<?php

/*
 | The `ventmail` OIDC client in the PRODUCTION ventSSO (deploy-ovh 4.). Run on the trust
 | box through ventSSO's ops container (`artisan tinker --execute`), see RUNBOOK-mail §4:
 |
 |   ops tinker --execute="$(cat ventsso-client.php)" 2>/dev/null | ssh mail '…/set-env.sh OIDC_CLIENT_SECRET'
 |
 | Idempotent. STDOUT carries ONE value only: the client secret, and only when the client
 | is created or VENTMAIL_ROTATE=1 (a confidential client's secret exists in plaintext only
 | at that moment). Everything else (the client id, which is not secret) goes to STDERR.
 |
 | Mirrors AdminClientController::store + ClientClaimsController (same models, same
 | ClientClaims::replace), so the v1.2 claim catalogue and consent behave exactly as if the
 | client had been made in the admin UI.
 */

use App\Claims\ClientClaims;
use App\Models\ClientRole;
use App\Models\OauthClient;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

$err = fn (string $line) => fwrite(STDERR, "[ventsso-client] {$line}\n");
$app = 'https://app.ventmail.app';

$client = OauthClient::query()->where('name', 'ventmail')->first();
$secret = null;

if ($client === null) {
    $secret = Str::random(40);
    $created = Passport::client()->forceCreate([
        'name' => 'ventmail',
        'description' => 'ventMail — e-mail infrastruktúra (app.ventmail.app)',
        'secret' => $secret,
        'redirect_uris' => [$app.'/auth/callback'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'scopes' => ['openid', 'profile', 'email'],
        'access_mode' => 'open',
        'brand_name' => 'ventMail',
        'revoked' => false,
    ]);
    $client = OauthClient::query()->findOrFail($created->getKey());
    $err('client created');
} elseif (getenv('VENTMAIL_ROTATE') === '1') {
    $secret = Str::random(40);
    $client->forceFill(['secret' => $secret])->save();
    $err('client secret rotated');
}

// RP-initiated logout lands back on the app root (ventMail sends post_logout_redirect_uri=/).
$client->forceFill(['post_logout_redirect_uris' => [$app.'/', $app]])->save();

// v1.2 claim catalogue: name + e-mail, both required, with the purpose shown on consent.
$purpose = 'A fiókod azonosításához és a csapattagok megjelenítéséhez.';
app(ClientClaims::class)->replace($client, [
    ['key' => 'name', 'requirement' => 'required', 'purpose' => $purpose],
    ['key' => 'email', 'requirement' => 'required', 'purpose' => $purpose],
]);
$client->forceFill(['sensitive_claims_in_id_token' => false])->save();

// The ventMail tenant roles (resource_access.ventmail.roles). Workspace membership itself
// stays in ventMail's own workspace_members.
foreach (['owner', 'admin', 'developer', 'editor', 'author', 'analyst'] as $role) {
    ClientRole::query()->firstOrCreate(['client_id' => $client->getKey(), 'name' => $role], ['is_default' => false]);
}

$err('client id: '.$client->getKey().' · claims: name, email (required) · roles synced');

if ($secret !== null) {
    fwrite(STDOUT, $secret.PHP_EOL);
}
