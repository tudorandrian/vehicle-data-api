<?php

declare(strict_types=1);

use VehicleData\Core\Importers\CommonsLicence;

it('accepts only public-domain and non-share-alike CC licences', function (?string $name, bool $free): void {
    expect(CommonsLicence::isFree($name))->toBe($free);
})->with([['Public domain', true], ['CC0', true], ['CC0 1.0', true], ['CC BY 4.0', true], ['CC BY-SA 4.0', false], ['CC BY-SA 3.0', false], ['GFDL', false], [null, false], ['', false], ['Fair use', false],
    ['CC BY-NC 4.0', false], ['CC BY-NC-SA 4.0', false], ['CC BY-ND 4.0', false], ['CC BY-NC-ND 4.0', false], ['cc by-nc 4.0', false], ['CC BY ND 4.0', false]]);
