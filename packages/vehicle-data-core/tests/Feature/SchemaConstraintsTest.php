<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use VehicleData\Core\Models\Make;
use VehicleData\Core\Support\Slug;

it('refuses a fleet count without a year and vice versa', function (): void {
    expect(fn () => Make::factory()->create(['ro_fleet_count' => 10, 'ro_fleet_year' => null]))->toThrow(QueryException::class)
        ->and(fn () => Make::factory()->create(['ro_fleet_count' => null, 'ro_fleet_year' => 2025]))->toThrow(QueryException::class);
});

it('accepts both or neither', function (): void {
    Make::factory()->create(['ro_fleet_count' => 10, 'ro_fleet_year' => 2025]);
    Make::factory()->create(['ro_fleet_count' => null, 'ro_fleet_year' => null]);
    expect(Make::count())->toBe(2);
});

it('sorts Romanian names with the Romanian collation', function (): void {
    foreach (['Șerban', 'Sandu', 'Tudor', 'Țepeș'] as $n) {
        Make::factory()->create(['name' => $n, 'slug' => Slug::make($n)]);
    }
    expect(Make::query()->orderBy('name')->pluck('name')->all())->toBe(['Sandu', 'Șerban', 'Tudor', 'Țepeș']);
});
