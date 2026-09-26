<?php

declare(strict_types=1);

namespace VehicleData\Core\Taxonomies;

final class TaxonomyDefinitions
{
    /** @return array<string, array{description: string, terms: list<string>}> */
    public static function all(): array
    {
        return [
            'fuel' => ['description' => 'Energy source of the vehicle (EEA Ft and DRPCIV combustibil, normalised)', 'terms' => [
                'petrol', 'diesel', 'electric', 'petrol_hybrid', 'diesel_hybrid', 'hybrid', 'lpg', 'petrol_lpg', 'cng', 'petrol_cng', 'lng', 'hydrogen', 'e85', 'other',
            ]],
            'eu_category' => ['description' => 'EU vehicle category (Regulation (EU) 2018/858)', 'terms' => [
                'm1', 'm1g', 'n1', 'n1g', 'm2', 'm3', 'n2', 'n3',
            ]],
            'national_category' => ['description' => 'Romanian registration category (DRPCIV categorie națională)', 'terms' => [
                'autoturism', 'autoutilitara', 'autobuz', 'microbuz', 'automobil_mixt', 'autospecializata', 'autospeciala', 'autorulota', 'autotractor', 'autoremorcher', 'autovehicul_special', 'moped', 'motocicleta', 'motocar',
            ]],
            'euro_norm' => ['description' => 'Emission stage (Euro 1–6, 6d, 6e) derived from the type-approval stage code', 'terms' => [
                'euro_1', 'euro_2', 'euro_3', 'euro_4', 'euro_5', 'euro_6', 'euro_6d', 'euro_6e', 'non_euro',
            ]],
            'body_type' => ['description' => 'Body style (no source in v1; terms only)', 'terms' => [
                'sedan', 'hatchback', 'estate', 'suv', 'coupe', 'convertible', 'mpv', 'pickup', 'van',
            ]],
            'gearbox' => ['description' => 'Transmission type (no source in v1; terms only)', 'terms' => ['manual', 'automatic']],
            'drive' => ['description' => 'Driven axles (no source in v1; terms only)', 'terms' => ['front', 'rear', 'all']],
            'colour' => ['description' => 'Exterior colour family (no source in v1; terms only)', 'terms' => [
                'white', 'black', 'grey', 'silver', 'blue', 'red', 'green', 'yellow', 'orange', 'brown', 'beige', 'purple', 'gold',
            ]],
        ];
    }
}
