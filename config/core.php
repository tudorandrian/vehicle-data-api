<?php

declare(strict_types=1);

return [
    'locales' => ['ro', 'en'],
    'default_locale' => 'ro',
    'trusted_proxies' => array_values(array_filter(explode(',', (string) env('CORE_TRUSTED_PROXIES', '')))),
    'max_body_bytes' => (int) env('CORE_MAX_BODY_BYTES', 65536),
    'import_reject_share' => (float) env('CORE_IMPORT_REJECT_SHARE', 0.05),
    // vPIC's GetWMIsForManufacturer returns both 3-character WMIs (the only kind this catalogue
    // models) and 6-character ones (the second VIN block, out of scope): the latter are always
    // rejected as `wmi_length`, so the `wmi` source runs with a much higher tolerance than the
    // default reject share instead of that expected rejection rate aborting every real import.
    'import_reject_share_wmi' => (float) env('CORE_IMPORT_REJECT_SHARE_WMI', 1.0),
    // Discodata table per year for the EEA CO2 monitoring source (vehicle:import eea --year=…).
    'eea_tables' => ['2023' => 'co2cars_2023Pv27', '2024' => 'co2cars_2024Fv30', '2025' => 'co2cars_2025Pv31'],
    'eea_country' => env('CORE_EEA_COUNTRY', 'RO'),
    // data.gov.ro resource per reference year for the DRPCIV fleet source (vehicle:import ro-fleet --year=…).
    'ro_fleet_resources' => [
        '2025' => 'https://data.gov.ro/dataset/b93e0946-2592-4ed7-a520-e07cba6acd07/resource/822c3ee4-0496-4fe2-b921-f497dd597a9b/download/parc-auto-fara-combustibil.csv',
    ],
    'docs_try_it_key' => env('CORE_DOCS_TRY_IT_KEY'),
    'auth_fail_per_minute' => (int) env('CORE_AUTH_FAIL_PER_MINUTE', 30),
    'per_page_default' => 25,
    'per_page_max' => 100,
    'request_ip_retention_days' => 30,
    // Base directory LogsCommand globs for laravel-*.log; overridable so tests never touch the real storage/logs directory.
    'logs_path' => storage_path('logs'),
    // route prefix => ['deprecation' => RFC 9651 date string, 'sunset' => ISO date, 'link' => URL]
    'deprecations' => [],
];
