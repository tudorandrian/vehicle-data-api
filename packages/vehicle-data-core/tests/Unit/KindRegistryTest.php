<?php

declare(strict_types=1);

use VehicleData\Core\Kinds\KindRegistry;
use VehicleData\Core\Kinds\Schemas\CarSpecificationSchema;
use VehicleData\Core\Kinds\SchemaValidator;

it('registers car by default and rejects unknown kinds', function (): void {
    $r = app(KindRegistry::class);
    expect($r->kinds())->toBe(['car'])->and($r->schema('car'))->toBeInstanceOf(CarSpecificationSchema::class)
        ->and($r->has('car'))->toBeTrue()->and($r->has('drone'))->toBeFalse()
        ->and(fn () => $r->schema('drone'))->toThrow(InvalidArgumentException::class);
});

it('validates car specifications against the JSON schema', function (): void {
    $schema = app(KindRegistry::class)->schema('car')->jsonSchema();
    expect(SchemaValidator::errors(['doors' => 5, 'euro_stage_raw' => '6AP', 'fuel_mode' => 'M'], $schema))->toBe([])
        ->and(SchemaValidator::errors([], $schema))->toBe([])
        ->and(SchemaValidator::errors(['doors' => 'five'], $schema))->not->toBe([])
        ->and(SchemaValidator::errors(['unknown_key' => 1], $schema))->not->toBe([]);
});
