<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Spectator\Spectator;
use VehicleData\Core\Contracts\Enricher;
use VehicleData\Core\Http\Query\Envelope;
use VehicleData\Core\Http\Resources\Enrichers;
use VehicleData\Core\Http\Resources\EnrichmentContext;
use VehicleData\Core\Models\Manufacturer;

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    [, $this->key] = keyed();
    Manufacturer::factory()->create(['slug' => 'fiat', 'name' => 'Fiat']);
});

/**
 * Runs a Spectator assertion that must fail on the response schema (not on
 * the status or a missing spec) and returns its message.
 */
function contractViolation(Closure $assertion): string
{
    try {
        $assertion();
    } catch (ErrorException $e) {
        expect($e->getMessage())->toContain('The properties must match schema: data')
            ->not->toContain('Expected response status code');

        return $e->getMessage();
    }

    throw new RuntimeException('The response unexpectedly satisfied the contract.');
}

/**
 * Registers a test-only GET /v1/manufacturers/{key} (same URI - matching the current
 * route parameter name so it replaces, not just shadows, the real route in Laravel's
 * URI-keyed route table - and `core` middleware, so Spectator validates it against the
 * same operation) whose payload is built here and passed through $mutate - Enrichers
 * never run.
 *
 * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
 */
function manufacturerRouteReturning(callable $mutate): void
{
    Route::middleware('core')->get('v1/manufacturers/{key}', function (string $key) use ($mutate) {
        $m = Manufacturer::query()->where('slug', $key)->firstOrFail();
        $data = ['id' => $m->public_id, 'slug' => $m->slug, 'name' => $m->name, 'country_code' => $m->country_code, 'founded_year' => $m->founded_year, 'parent' => $m->parent_slug, 'website' => $m->website];

        return Envelope::item($mutate($data), [], 'ro');
    });
}

it('fails the contract when a class-3 key is emitted as null', function (): void {
    app()->bind('t.null', fn () => new class implements Enricher
    {
        public function supports(string $resource): bool
        {
            return true;
        }

        public function enrich(Model $record, array $payload, EnrichmentContext $ctx): array
        {
            return $payload + ['logo' => null];
        }
    });
    app()->tag(['t.null'], 'core.enrichers');
    app()->forgetInstance(Enrichers::class);
    $res = $this->getJson('/v1/manufacturers/fiat', bearer($this->key))->assertOk();
    expect($res->json())->toHaveKey('data.logo')->and($res->json('data.logo'))->toBeNull()
        ->and(contractViolation(fn () => $res->assertValidResponse(200)))->toContain('logo');
});

it('fails the contract when a class-2 key is missing from the payload', function (): void {
    // Control: the unmodified payload satisfies the contract.
    manufacturerRouteReturning(fn (array $data): array => $data);
    $this->getJson('/v1/manufacturers/fiat')->assertOk()->assertValidResponse(200);

    manufacturerRouteReturning(function (array $data): array {
        unset($data['website']);

        return $data;
    });
    $res = $this->getJson('/v1/manufacturers/fiat')->assertOk();
    expect($res->json('data'))->not->toHaveKey('website')
        ->and(contractViolation(fn () => $res->assertValidResponse(200)))->toContain('website');
});

it('turns an enricher that drops a class-2 key into a 500 (enricher guard)', function (?string $website): void {
    Manufacturer::query()->where('slug', 'fiat')->update(['website' => $website]);
    app()->bind('t.drop', fn () => new class implements Enricher
    {
        public function supports(string $resource): bool
        {
            return true;
        }

        public function enrich(Model $record, array $payload, EnrichmentContext $ctx): array
        {
            unset($payload['website']);

            return $payload;
        }
    });
    app()->tag(['t.drop'], 'core.enrichers');
    app()->forgetInstance(Enrichers::class);
    $this->getJson('/v1/manufacturers/fiat', bearer($this->key))->assertStatus(500)->assertValidResponse(500);
})->with([null, 'https://example.org']);
