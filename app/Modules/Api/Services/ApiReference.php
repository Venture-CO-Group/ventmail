<?php

declare(strict_types=1);

namespace App\Modules\Api\Services;

use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

/**
 * AZ APPON BELÜLI API-REFERENCIA (v1.1 · 7. modul, D177) — a `docs/openapi/ventmail-v1.yaml`
 * szerződésből GENERÁLVA, nem kézzel írva.
 *
 * A szerződés az egyetlen forrás (golden rule 7: drift = 0): ha a referencia külön szövegként
 * élne, előbb-utóbb mást mondana, mint az API. A scope-ot a route MAGA adja (`api.scope:*`
 * middleware), nem a leírás — így a dokumentáció azt mutatja, amit a kapu valóban kér.
 *
 * A feldolgozott alak a fájl módosítási idejére kulcsolva cache-elt.
 */
final class ApiReference
{
    /**
     * „HAMAROSAN" felületek (D15): a végpontjaik a szerződés részei, a referenciában is ott
     * vannak (drift = 0), de jelölve — a funkció még nem általánosan elérhető.
     */
    public const SOON_PREFIXES = ['/routes', '/inbound', '/validate'];

    /** @return array{title: string, version: string, baseUrl: string, tags: list<array{name: string, description: string}>, operations: list<array<string, mixed>>} */
    public function build(): array
    {
        $path = base_path('docs/openapi/ventmail-v1.yaml');
        // A `v2` a feldolgozott ALAK verziója (UI-polírozás · 9: soon / errorCodes / controls).
        $key = 'api-reference:v2:'.(string) @filemtime($path);

        return Cache::remember($key, 3600, fn (): array => $this->parse(Yaml::parseFile($path)));
    }

    /** @param array<string, mixed> $spec */
    private function parse(array $spec): array
    {
        $scopes = $this->routeScopes();
        $operations = [];

        foreach ((array) ($spec['paths'] ?? []) as $path => $methods) {
            foreach ((array) $methods as $method => $operation) {
                if (! in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true) || ! is_array($operation)) {
                    continue;
                }

                $body = $this->requestBody($spec, $operation);

                $operations[] = [
                    'id' => (string) ($operation['operationId'] ?? $method.str_replace(['/', '{', '}', '.'], '-', (string) $path)),
                    'method' => strtoupper((string) $method),
                    'path' => (string) $path,
                    'tag' => (string) (($operation['tags'] ?? ['general'])[0] ?? 'general'),
                    'summary' => (string) ($operation['summary'] ?? ''),
                    'description' => trim((string) ($operation['description'] ?? '')),
                    'scope' => $scopes[strtoupper((string) $method).' '.$path] ?? null,
                    'soon' => self::isSoon((string) $path),
                    'parameters' => $this->parameters($spec, (array) ($operation['parameters'] ?? [])),
                    'body' => $body['fields'],
                    'example' => $body['example'],
                    'contentType' => $body['contentType'],
                    'responses' => array_map(
                        static fn ($code, $response) => ['code' => (string) $code, 'description' => trim((string) (is_array($response) ? ($response['description'] ?? '') : ''))],
                        array_keys((array) ($operation['responses'] ?? [])),
                        array_values((array) ($operation['responses'] ?? [])),
                    ),
                ];
            }
        }

        return [
            'title' => (string) ($spec['info']['title'] ?? 'API'),
            'version' => (string) ($spec['info']['version'] ?? ''),
            'baseUrl' => (string) ($spec['servers'][0]['url'] ?? ''),
            'tags' => array_values(array_map(
                static fn (array $tag) => ['name' => (string) $tag['name'], 'description' => (string) ($tag['description'] ?? '')],
                array_filter((array) ($spec['tags'] ?? []), 'is_array'),
            )),
            'operations' => $operations,
            // A STABIL hibakódok — a szerződés `ErrorCode` enumjából (UI-polírozás · 9).
            'errorCodes' => array_values(array_map('strval', (array) data_get($spec, 'components.schemas.ErrorCode.enum', []))),
            // A Mailgun-paritású VEZÉRLŐK (`o:` / `t:` / `recipient-variables`, és a `h:` / `v:` minta)
            // a strukturált küldés kérés-sémájából.
            'controls' => $this->controls($spec),
        ];
    }

    public static function isSoon(string $path): bool
    {
        foreach (self::SOON_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return list<array{name: string, type: string, description: string}>
     */
    private function controls(array $spec): array
    {
        $schema = $this->flatten($spec, (array) data_get($spec, 'components.schemas.SendMessageRequest', []));
        $out = [];

        foreach ((array) ($schema['properties'] ?? []) as $name => $property) {
            $name = (string) $name;

            if (! preg_match('/^(o:|t:|recipient-variables$)/', $name)) {
                continue;
            }

            $property = $this->flatten($spec, (array) $property);
            $out[] = ['name' => $name, 'type' => $this->typeOf($spec, $property), 'description' => trim((string) ($property['description'] ?? ''))];
        }

        $extra = trim((string) data_get($schema, 'additionalProperties.description', ''));

        if ($extra !== '') {
            $out[] = ['name' => 'h:X-* · v:*', 'type' => 'string', 'description' => $extra];
        }

        return $out;
    }

    /** `METHOD /path` → az `api.scope:*` middleware scope-ja. @return array<string, string> */
    private function routeScopes(): array
    {
        $out = [];

        /** @var RouteDefinition $route */
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'v1/')) {
                continue;
            }

            $path = '/'.preg_replace('/\{(\w+)\??\}/', '{$1}', substr($route->uri(), 3));

            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'api.scope:')) {
                    foreach ($route->methods() as $method) {
                        $out[$method.' '.$path] = substr($middleware, strlen('api.scope:'));
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  list<mixed>  $parameters
     * @return list<array{name: string, in: string, required: bool, type: string, description: string}>
     */
    private function parameters(array $spec, array $parameters): array
    {
        $out = [];

        foreach ($parameters as $parameter) {
            $parameter = $this->resolve($spec, (array) $parameter);

            $out[] = [
                'name' => (string) ($parameter['name'] ?? ''),
                'in' => (string) ($parameter['in'] ?? ''),
                'required' => (bool) ($parameter['required'] ?? false),
                'type' => $this->typeOf($spec, (array) ($parameter['schema'] ?? [])),
                'description' => trim((string) ($parameter['description'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $operation
     * @return array{fields: list<array<string, mixed>>, example: mixed, contentType: ?string}
     */
    private function requestBody(array $spec, array $operation): array
    {
        $content = (array) ($operation['requestBody']['content'] ?? []);
        $contentType = array_key_exists('application/json', $content) ? 'application/json' : (array_key_first($content) ?: null);

        if ($contentType === null) {
            return ['fields' => [], 'example' => null, 'contentType' => null];
        }

        $schema = $this->flatten($spec, (array) ($content[$contentType]['schema'] ?? []));
        $required = (array) ($schema['required'] ?? []);
        $fields = [];
        $example = [];

        foreach ((array) ($schema['properties'] ?? []) as $name => $property) {
            $property = $this->flatten($spec, (array) $property);

            $fields[] = [
                'name' => (string) $name,
                'type' => $this->typeOf($spec, $property),
                'required' => in_array($name, $required, true),
                'description' => trim((string) ($property['description'] ?? '')),
            ];

            if (in_array($name, $required, true) || count($example) < 6) {
                $example[$name] = $this->exampleOf((string) $name, $property);
            }
        }

        return ['fields' => $fields, 'example' => $example === [] ? null : $example, 'contentType' => $contentType];
    }

    /** `$ref` feloldása és az `allOf` összefésülése (egy szint mélyen elég a mi sémáinkhoz). */
    private function flatten(array $spec, array $schema): array
    {
        $schema = $this->resolve($spec, $schema);

        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            $merged = ['type' => 'object', 'properties' => [], 'required' => []];

            foreach ($schema['allOf'] as $part) {
                $part = $this->resolve($spec, (array) $part);
                $merged['properties'] = [...$merged['properties'], ...(array) ($part['properties'] ?? [])];
                $merged['required'] = [...$merged['required'], ...(array) ($part['required'] ?? [])];
            }

            return $merged;
        }

        return $schema;
    }

    private function resolve(array $spec, array $node): array
    {
        if (isset($node['$ref']) && is_string($node['$ref'])) {
            $target = data_get($spec, str_replace('/', '.', ltrim($node['$ref'], '#/')), []);

            return is_array($target) ? $target : [];
        }

        return $node;
    }

    private function typeOf(array $spec, array $schema): string
    {
        $schema = $this->resolve($spec, $schema);

        if (isset($schema['enum'])) {
            return implode(' | ', array_map('strval', (array) $schema['enum']));
        }

        $type = $schema['type'] ?? 'object';
        $type = is_array($type) ? implode('|', $type) : (string) $type;

        if ($type === 'array') {
            return $this->typeOf($spec, (array) ($schema['items'] ?? [])).'[]';
        }

        return $type.(isset($schema['format']) ? ' ('.$schema['format'].')' : '');
    }

    /** Beszédes példaérték a kódmintákhoz — név és típus alapján, valódi címzett nélkül. */
    private function exampleOf(string $name, array $schema): mixed
    {
        if (array_key_exists('example', $schema)) {
            return $schema['example'];
        }

        if (isset($schema['enum'])) {
            return ((array) $schema['enum'])[0];
        }

        $type = is_array($schema['type'] ?? null) ? ($schema['type'][0] ?? 'string') : ($schema['type'] ?? 'string');

        return match (true) {
            $name === 'from' => 'Shop <no-reply@mail.example.com>',
            $name === 'to' => ['recipient@example.com'],
            in_array($name, ['email', 'recipient'], true) => 'recipient@example.com',
            $name === 'subject' => 'Order confirmation',
            $name === 'html' => '<p>Hello {{name}}</p>',
            $name === 'text' => 'Hello {{name}}',
            $name === 'channel' => 'transactional',
            $name === 'url' => 'https://example.com/hooks/ventmail',
            $type === 'array' => [],
            $type === 'integer' || $type === 'number' => 1,
            $type === 'boolean' => false,
            $type === 'object' => new \stdClass,
            default => $name,
        };
    }
}
