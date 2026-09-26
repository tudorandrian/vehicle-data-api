<?php

declare(strict_types=1);

use VehicleData\Core\Http\Resources\TermResource;
use VehicleData\Core\Models\Taxonomy;
use VehicleData\Core\Models\TaxonomyLabel;
use VehicleData\Core\Models\TaxonomyTerm;

it('falls back to the term code when the requested language has no label', function (): void {
    $taxonomy = Taxonomy::query()->create(['name' => 'colour', 'description' => 'Test taxonomy']);
    $term = TaxonomyTerm::query()->create(['taxonomy_id' => $taxonomy->id, 'code' => 'teal', 'sort_order' => 0, 'parent_code' => null]);
    TaxonomyLabel::query()->create(['term_id' => $term->id, 'locale' => 'ro', 'label' => 'Turcoaz']);
    $term->load('labels');

    expect(TermResource::make($term, 'ro'))->toBe(['id' => $term->public_id, 'code' => 'teal', 'label' => 'Turcoaz', 'sort_order' => 0, 'parent_code' => null])
        ->and(TermResource::make($term, 'en'))->toBe(['id' => $term->public_id, 'code' => 'teal', 'label' => 'teal', 'sort_order' => 0, 'parent_code' => null]);
});
