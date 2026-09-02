<?php

declare(strict_types=1);
use Symfony\Component\Yaml\Yaml;

/*
 | A CI titkot lát (VENTURE_PACKAGES_TOKEN), és a PR-ből érkező kódot futtatja (npm ci,
 | vite build lifecycle-scriptek). Ha a checkout a tokent a .git/config-ba írja, egy
 | postinstall script kiexfiltrálhatja (security-review B2). Ezek az invariánsok őrzik.
 */

function ciWorkflow(): string
{
    return file_get_contents(base_path('.github/workflows/ci.yml'));
}

it('a workflow YAML érvényes', function () {
    // Egy idézőjel nélküli kettőspont a step-névben „workflow file issue"-val bukatja az
    // EGÉSZ futást — a hiba a GitHub-logban nem is látszik. Ezért lokálisan parse-oljuk.
    $workflow = Yaml::parseFile(base_path('.github/workflows/ci.yml'));

    expect($workflow['jobs'] ?? [])->toHaveKeys(['backend', 'frontend', 'security']);
});

it('a workflow szűkíti a GITHUB_TOKEN jogosultságát', function () {
    expect(ciWorkflow())->toContain('permissions:')->toContain('contents: read');
});

it('egyetlen checkout sem hagyja lemezen a hitelesítőt', function () {
    $checkouts = preg_match_all('/uses:\s*actions\/checkout@/', ciWorkflow());
    $optOuts = preg_match_all('/persist-credentials:\s*false/', ciWorkflow());

    // >=, mert a magyarázó komment is tartalmazza a kapcsoló nevét.
    expect($optOuts)->toBeGreaterThanOrEqual($checkouts, 'minden checkout lépéshez kell persist-credentials: false');
});

it('minden third-party action commit SHA-ra van pinelve', function () {
    preg_match_all('/uses:\s*([^\s@]+)@([^\s#]+)/', ciWorkflow(), $matches, PREG_SET_ORDER);

    foreach ($matches as [$full, $action, $ref]) {
        expect($ref)->toMatch('/^[0-9a-f]{40}$/', "mozgó tag pinelés helyett: {$action}@{$ref}");
    }
});

it('a letöltött biztonsági eszköz integritása ellenőrzött', function () {
    expect(ciWorkflow())->toContain('sha256sum');
});
