<?php

declare(strict_types=1);

namespace VehicleData\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use VehicleData\Core\Models\Make;
use VehicleData\Core\Models\VehicleModel;
use VehicleData\Core\Support\Slug;

/**
 * @extends Factory<VehicleModel>
 */
final class VehicleModelFactory extends Factory
{
    protected $model = VehicleModel::class;

    /**
     * Derives the default slug lazily from the make's own name so fixture data reads
     * naturally, without eagerly creating a make of its own: `make_id` stays a lazy
     * `Make::factory()` relation (created only if the caller doesn't override `make_id`,
     * exactly like before), and the `slug` closure resolves it from `$attributes` -
     * Laravel expands `make_id` before `slug` (attribute keys are resolved in array
     * order, and `make_id` is declared first), so by the time the closure runs,
     * `$attributes['make_id']` is always the real, final id: either the caller's
     * override or the one Make::factory() just created. An explicit `slug` passed to
     * `create()`/`make()` always wins over this array too, since Laravel merges the
     * caller's attributes over it and only invokes a key's closure when the key is
     * still a closure after that merge. (Previously this was done in an
     * `afterMaking()` hook that unconditionally rewrote `slug` from `make_id` after
     * the merge - which silently discarded any explicit `slug` override; an earlier
     * attempt at fixing that eagerly called `Make::factory()->create()` here instead,
     * which broke `make()` - non-persisting - and any caller-supplied `make_id`
     * override, by always inserting an extra, orphaned make. Resolving lazily from
     * `$attributes` avoids both problems.)
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->word());

        return [
            'make_id' => Make::factory(),
            'slug' => fn (array $attributes) => Slug::make(Make::query()->findOrFail((int) $attributes['make_id'])->name.' '.$name),
            'name' => $name,
            'first_year' => 2015,
            'last_year' => null,
        ];
    }
}
