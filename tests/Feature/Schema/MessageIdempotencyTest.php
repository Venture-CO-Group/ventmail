<?php

declare(strict_types=1);

use App\Modules\Sending\Models\Message;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 | M0-2 elfogadási kritérium #13 — az Idempotency-Key workspace-enként egyedi (golden rule 5).
 | Az A1 Messages API erre épül: kétszeri POST ugyanazzal a kulccsal nem küld kétszer.
 */

beforeEach(function () {
    $this->a = Workspace::factory()->create();
    $this->b = Workspace::factory()->create();
});

function message(array $attributes = []): Message
{
    return Message::create(array_merge([
        'channel' => 'transactional',
        'from_email' => 'no-reply@pelda.hu',
        'recipients' => ['cimzett@example.test'],
    ], $attributes));
}

it('ugyanaz az idempotency-key workspace-en belül ütközik', function () {
    app(WorkspaceContext::class)->set($this->a);

    message(['idempotency_key' => 'kulcs-1']);

    expect(fn () => message(['idempotency_key' => 'kulcs-1']))->toThrow(QueryException::class);
});

it('ugyanaz a kulcs másik workspace-ben megengedett', function () {
    app(WorkspaceContext::class)->set($this->a);
    message(['idempotency_key' => 'kulcs-1']);

    app(WorkspaceContext::class)->set($this->b);
    $masodik = message(['idempotency_key' => 'kulcs-1']);

    expect($masodik->exists)->toBeTrue();
});

it('a csatorna és az alapállapot rögzül', function () {
    app(WorkspaceContext::class)->set($this->a);

    $transactional = message();
    $marketing = message(['channel' => 'marketing', 'idempotency_key' => 'k2']);

    expect($transactional->isTransactional())->toBeTrue();
    expect($transactional->isMarketing())->toBeFalse();
    expect($marketing->isMarketing())->toBeTrue();
    expect($transactional->status)->toBe('queued');
    expect($transactional->testmode)->toBeFalse();
});

it('a tag-ek és változók strukturáltan tárolódnak', function () {
    app(WorkspaceContext::class)->set($this->a);

    $message = message([
        'tags' => ['welcome', 'onboarding'],
        'variables' => ['user_id' => 42, 'plan' => 'pro'],
        'headers' => ['X-Kampany' => 'tavasz'],
    ]);

    $friss = $message->fresh();

    // A jsonb nem őrzi a kulcsok sorrendjét (Postgres-viselkedés), ezért kulcs szerint nézzük.
    expect($friss->tags)->toBe(['welcome', 'onboarding']);
    expect($friss->variables)->toEqualCanonicalizing(['user_id' => 42, 'plan' => 'pro']);
    expect($friss->headers)->toBe(['X-Kampany' => 'tavasz']);
});
