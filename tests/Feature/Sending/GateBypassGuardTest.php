<?php

declare(strict_types=1);

use App\Modules\Events\Models\MessageEvent;
use App\Modules\Sending\Contracts\SendingDriver;
use App\Modules\Sending\Models\Message;
use App\Modules\Sending\Services\MessageDispatcher;
use App\Modules\Tenancy\Models\Workspace;
use App\Modules\Tenancy\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;

/*
 | M0-3 elfogadási kritérium #22 — golden rule 2: a PreSendGate MEGKERÜLHETETLEN.
 |
 | A korábbi változat szövegkeresés volt, és a legvalószínűbb megkerülést NEM fogta:
 | egy konstruktor-injektált `SendingDriver` (`$this->driver->send(...)`) átcsúszott rajta.
 | Épp ezt az alakot fogja felvenni az A2 SMTP-relay worker. Ez a teszt ezért REFLEXIÓVAL
 | dolgozik: a típusfüggőséget nézi, nem a szöveget.
 */

function projectClasses(): array
{
    $classes = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        $relative = str_replace([app_path().'/', '.php'], '', $file->getPathname());
        $class = 'App\\'.str_replace('/', '\\', $relative);

        if (class_exists($class) || interface_exists($class)) {
            $classes[] = $class;
        }
    }

    return $classes;
}

it('a SendingDriver-t kizárólag a MessageDispatcher kaphatja meg', function () {
    $allowed = [MessageDispatcher::class];

    $offenders = [];

    foreach (projectClasses() as $class) {
        if (in_array($class, $allowed, true)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        // A driverek maguk implementálják az interfészt; a provider a kötés helye.
        if ($reflection->implementsInterface(SendingDriver::class)
            || str_ends_with($class, 'ServiceProvider')) {
            continue;
        }

        foreach ($reflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();

                if ($type instanceof ReflectionNamedType && $type->getName() === SendingDriver::class) {
                    $offenders[] = $class.'::'.$method->getName();
                }
            }
        }

        foreach ($reflection->getProperties() as $property) {
            $type = $property->getType();

            if ($type instanceof ReflectionNamedType && $type->getName() === SendingDriver::class) {
                $offenders[] = $class.'::$'.$property->getName();
            }
        }
    }

    expect($offenders)->toBeEmpty(
        'a MessageDispatcheren kívül hozzáfér a driverhez: '.implode(', ', $offenders)
    );
});

it('a container is kikényszeríti, hogy csak a dispatcher kapja a drivert', function () {
    $source = file_get_contents(app_path('Modules/Sending/Providers/SendingServiceProvider.php'));

    expect($source)
        ->toContain('->when(MessageDispatcher::class)')
        ->toContain('->needs(SendingDriver::class)');
});

it('a driver feloldása sehol nem történik a Sending modulon kívül', function () {
    $offenders = [];

    $roots = array_filter([app_path(), base_path('routes'), base_path('database')], 'is_dir');

    foreach ($roots as $root) {
        foreach (File::allFiles($root) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace(base_path().'/', '', $file->getPathname());

            if (str_contains($path, 'app/Modules/Sending/')) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            // KÓD-hivatkozás, nem puszta szó: a dokumentációs említés megengedett.
            $codeUse = '/use\s+App\\\\Modules\\\\Sending\\\\Contracts\\\\SendingDriver\s*;'
                .'|SendingDriver::class/';

            if (preg_match($codeUse, $contents)) {
                $offenders[] = $path;
            }
        }
    }

    expect($offenders)->toBeEmpty('a Sending modulon kívül hivatkozik a driverre: '.implode(', ', $offenders));
});

it('a dispatcher gate-en át nem ment üzenetet elutasít', function () {
    // Viselkedés-teszt a korábbi tautológia (`toContain('accepted')`) helyett.
    $workspace = Workspace::factory()->create();
    app(WorkspaceContext::class)->set($workspace);

    $message = Message::create([
        'channel' => 'transactional',
        'from_email' => 'no-reply@pelda.hu',
        'recipients' => ['cimzett@example.test'],
    ]);

    expect($message->status)->toBe('queued');
    expect(fn () => app(MessageDispatcher::class)->dispatch($message))->toThrow(RuntimeException::class);
    expect(MessageEvent::count())->toBe(0);
})->group('database');

uses(RefreshDatabase::class)->group('database');
