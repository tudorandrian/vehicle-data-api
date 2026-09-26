<?php

declare(strict_types=1);

use VehicleData\Core\Importers\ImportOptions;
use VehicleData\Core\Importers\Sources\EeaSource;

it('rejects an invalid core.eea_country before querying Discodata', function (string $country): void {
    config()->set('core.eea_country', $country);
    $source = new EeaSource;
    expect(fn () => iterator_to_array($source->fetch(new ImportOptions(year: 2024))))
        ->toThrow(RuntimeException::class, "Invalid core.eea_country [{$country}]");
})->with(['romania', 'R', 'ROU', 'ro', 'R1', "RO';--"]);
