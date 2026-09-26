<?php

declare(strict_types=1);

/**
 * Every example the documentation shows, rendered by `vehicle:examples render` against the
 * seeded fixtures. `operation` is the OpenAPI path (with its template) the example belongs
 * to; `scopes` are the scopes of the key used. Order matters only for readability.
 */
return [
    ['name' => 'health', 'operation' => '/v1/health', 'path' => '/v1/health', 'scopes' => null, 'expect' => 200],
    ['name' => 'health-ready', 'operation' => '/v1/health/ready', 'path' => '/v1/health/ready', 'scopes' => ['catalogue:read'], 'expect' => 200],
    ['name' => 'taxonomies', 'operation' => '/v1/taxonomies', 'path' => '/v1/taxonomies', 'scopes' => ['catalogue:read'], 'expect' => 200],
    ['name' => 'taxonomy-fuel', 'operation' => '/v1/taxonomies/{key}', 'path' => '/v1/taxonomies/fuel', 'scopes' => ['catalogue:read'], 'expect' => 200],
    ['name' => 'taxonomy-national-category-en', 'operation' => '/v1/taxonomies/{key}', 'path' => '/v1/taxonomies/national_category', 'query' => ['lang' => 'en'], 'scopes' => ['catalogue:read'], 'expect' => 200],
    ['name' => 'manufacturers', 'operation' => '/v1/manufacturers', 'path' => '/v1/manufacturers', 'query' => ['per_page' => '3'], 'scopes' => ['catalogue:read'], 'expect' => 200],
    ['name' => 'manufacturer-bmw', 'operation' => '/v1/manufacturers/{key}', 'path' => '/v1/manufacturers/bmw', 'scopes' => ['catalogue:read'], 'expect' => 200],
    ['name' => 'makes', 'operation' => '/v1/makes', 'path' => '/v1/makes', 'query' => ['sort' => '-ro_fleet_count', 'per_page' => '3'], 'scopes' => ['catalogue:read'], 'expect' => 200],
    ['name' => 'make-dacia', 'operation' => '/v1/makes/{key}', 'path' => '/v1/makes/dacia', 'scopes' => ['catalogue:read'], 'expect' => 200],
    ['name' => 'make-dacia-by-id', 'operation' => '/v1/makes/{key}', 'path' => '/v1/makes/{id:make:dacia}', 'scopes' => ['catalogue:read'], 'expect' => 200],
    ['name' => 'make-dacia-models', 'operation' => '/v1/makes/{key}/models', 'path' => '/v1/makes/dacia/models', 'query' => ['sort' => '-ro_fleet_count', 'per_page' => '3'], 'scopes' => ['catalogue:read'], 'expect' => 200],
    ['name' => 'makes-csv', 'operation' => '/v1/makes', 'path' => '/v1/makes', 'query' => ['format' => 'csv', 'per_page' => '3'], 'scopes' => ['catalogue:read'], 'expect' => 200],
    ['name' => 'model-dacia-duster', 'operation' => '/v1/models/{key}', 'path' => '/v1/models/dacia-duster', 'scopes' => ['catalogue:read'], 'expect' => 200],
    ['name' => 'variants-duster-diesel-euro6d', 'operation' => '/v1/models/{key}/variants', 'path' => '/v1/models/dacia-duster/variants', 'query' => ['fuel' => 'diesel', 'euro_norm' => 'euro_6d'], 'scopes' => ['catalogue:read'], 'expect' => 200],
    ['name' => 'variants-ducato-n1', 'operation' => '/v1/models/{key}/variants', 'path' => '/v1/models/{slug:model:eu_category=n1}/variants', 'query' => ['eu_category' => 'n1'], 'scopes' => ['catalogue:read'], 'expect' => 200],
    ['name' => 'variant', 'operation' => '/v1/variants/{id}', 'path' => '/v1/variants/{id:variant:fuel=diesel,euro_norm=euro_6d,make=dacia}', 'scopes' => ['catalogue:read'], 'expect' => 200],
    ['name' => 'vin', 'operation' => '/v1/vin/{vin}', 'path' => '/v1/vin/WVWZZZ3CZWE000001', 'scopes' => ['vin:decode'], 'expect' => 200],
    ['name' => 'snapshot-makes', 'operation' => '/v1/snapshots/{resource}', 'path' => '/v1/snapshots/makes', 'scopes' => ['snapshot:read'], 'expect' => 200, 'body' => false],
    ['name' => 'openapi', 'operation' => '/openapi.yaml', 'path' => '/openapi.yaml', 'scopes' => null, 'expect' => 200, 'body' => false],
    ['name' => 'error-401', 'operation' => '/v1/makes', 'path' => '/v1/makes', 'scopes' => null, 'expect' => 401],
    ['name' => 'error-403-scope', 'operation' => '/v1/vin/{vin}', 'path' => '/v1/vin/WVWZZZ3CZWE000001', 'scopes' => ['catalogue:read'], 'expect' => 403],
    ['name' => 'error-404', 'operation' => '/v1/makes/{key}', 'path' => '/v1/makes/no-such-make', 'scopes' => ['catalogue:read'], 'expect' => 404],
    ['name' => 'error-422', 'operation' => '/v1/makes', 'path' => '/v1/makes', 'query' => ['per_page' => '500'], 'scopes' => ['catalogue:read'], 'expect' => 422],
    ['name' => 'error-400-vin', 'operation' => '/v1/vin/{vin}', 'path' => '/v1/vin/SHORT', 'scopes' => ['vin:decode'], 'expect' => 400],
    // A dedicated 1-request-per-minute key, primed with one (discarded) request first, so this
    // second, captured request lands over the limit - never shared with the catalogue:read key
    // every other example above uses. ExampleRenderer freezes the clock for the priming+capture
    // sequence so both requests always fall in the same fixed-window minute bucket, whatever the
    // real wall-clock time is when the render runs.
    ['name' => 'error-429', 'operation' => '/v1/makes', 'path' => '/v1/makes', 'scopes' => ['catalogue:read'], 'rate_per_minute' => 1, 'prime' => 1, 'expect' => 429],
];
