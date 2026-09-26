<?php

declare(strict_types=1);

use VehicleData\Core\Importers\Licence;

it('records the four admitted licences with attribution text and no share-alike', function (): void {
    foreach ([Licence::eea(), Licence::oglRou(), Licence::cc0(), Licence::usGov()] as $l) {
        expect($l->attribution)->not->toBe('')->and($l->url)->toStartWith('https://')->and($l->id)->not->toContain('SA');
    }
    expect(Licence::eea()->attribution)->toBe('Source: European Environment Agency (EEA)')
        ->and(Licence::oglRou()->attribution)->toBe('Contains public information under the Open Government Licence v1.0');
});

it('gives OGL-ROU a Romanian attribution with comma-below diacritics, and falls back to English for the others', function (): void {
    expect(Licence::oglRou()->attributionRo)->toBe('Conține informații publice sub Licența Guvernamentală Deschisă v1.0')
        ->and(Licence::oglRou()->attributionRo)->not->toContain('ţ')->not->toContain('ş');

    expect(Licence::eea()->attributionRo)->toBe(Licence::eea()->attribution)
        ->and(Licence::cc0()->attributionRo)->toBe(Licence::cc0()->attribution)
        ->and(Licence::usGov()->attributionRo)->toBe(Licence::usGov()->attribution);
});
