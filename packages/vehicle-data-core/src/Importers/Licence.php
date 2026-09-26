<?php

declare(strict_types=1);

namespace VehicleData\Core\Importers;

final readonly class Licence
{
    public string $attributionRo;

    public function __construct(public string $id, public string $name, public string $url, public string $attribution, ?string $attributionRo = null)
    {
        // Falls back to the English attribution unless a Romanian string is given explicitly.
        $this->attributionRo = $attributionRo ?? $attribution;
    }

    public static function eea(): self
    {
        return new self('CC-BY-4.0', 'Creative Commons Attribution 4.0 (EEA standard re-use policy)', 'https://www.eea.europa.eu/en/legal-notice', 'Source: European Environment Agency (EEA)');
    }

    public static function oglRou(): self
    {
        return new self(
            'OGL-ROU-1.0',
            'Open Government Licence Romania 1.0',
            'https://data.gov.ro/base/images/logoinst/OGL-ROU-1.0.pdf',
            'Contains public information under the Open Government Licence v1.0',
            'Conține informații publice sub Licența Guvernamentală Deschisă v1.0',
        );
    }

    public static function cc0(): self
    {
        return new self('CC0-1.0', 'Creative Commons Zero 1.0', 'https://creativecommons.org/publicdomain/zero/1.0/', 'Data from Wikidata (CC0)');
    }

    public static function usGov(): self
    {
        return new self('US-PD', 'United States Government work (public domain)', 'https://www.usa.gov/government-copyright', 'Source: NHTSA vPIC');
    }
}
