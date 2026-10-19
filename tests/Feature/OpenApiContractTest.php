<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * F10.1 — docs/openapi-v1.json describes exactly the bearer-token routes that exist: a new /api/v1
 * route without a spec entry (or a spec entry for a removed route) fails here.
 */
class OpenApiContractTest extends TestCase
{
    /** @return array<string, mixed> */
    private function spec(): array
    {
        $spec = json_decode((string) file_get_contents(base_path('docs/openapi-v1.json')), true);
        $this->assertIsArray($spec, 'docs/openapi-v1.json must be valid JSON');

        return $spec;
    }

    /** @return list<string> "METHOD /path" for each v1 route, as OpenAPI writes it */
    private function routes(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $out[] = $method.' /'.substr($route->uri(), strlen('api/v1/'));
            }
        }
        sort($out);

        return $out;
    }

    /** @return list<string> */
    private function documented(): array
    {
        $out = [];
        foreach ($this->spec()['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if (in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    $out[] = strtoupper($method).' '.$path;
                }
            }
        }
        sort($out);

        return $out;
    }

    public function test_every_v1_route_is_documented_and_nothing_extra(): void
    {
        $this->assertSame($this->routes(), $this->documented());
    }

    public function test_the_spec_declares_bearer_security_and_known_references_resolve(): void
    {
        $spec = $this->spec();

        $this->assertSame('3.0.3', $spec['openapi']);
        $this->assertArrayHasKey('bearerAuth', $spec['components']['securitySchemes']);

        preg_match_all('/"\$ref":\s*"#\/components\/([A-Za-z]+)\/([A-Za-z]+)"/', (string) file_get_contents(base_path('docs/openapi-v1.json')), $refs, PREG_SET_ORDER);
        $this->assertNotEmpty($refs);
        foreach ($refs as [, $kind, $name]) {
            $this->assertArrayHasKey($name, $spec['components'][$kind], "dangling \$ref to $kind/$name");
        }
    }
}
