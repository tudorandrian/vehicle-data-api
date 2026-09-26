<?php

declare(strict_types=1);

namespace VehicleData\Core\Importers;

/** Maps NHTSA vPIC's free-text `Country` values to ISO 3166-1 alpha-2 codes; unknown names map to null. */
final class CountryName
{
    private const MAP = [
        'GERMANY' => 'DE', 'UNITED STATES (USA)' => 'US', 'JAPAN' => 'JP', 'FRANCE' => 'FR', 'ITALY' => 'IT',
        'SPAIN' => 'ES', 'UNITED KINGDOM (UK)' => 'GB', 'SOUTH KOREA' => 'KR', 'KOREA (SOUTH)' => 'KR',
        'CZECH REPUBLIC' => 'CZ', 'ROMANIA' => 'RO', 'MEXICO' => 'MX', 'CANADA' => 'CA', 'CHINA' => 'CN',
        'INDIA' => 'IN', 'BRAZIL' => 'BR', 'SWEDEN' => 'SE', 'NETHERLANDS' => 'NL', 'BELGIUM' => 'BE',
        'AUSTRIA' => 'AT', 'POLAND' => 'PL', 'HUNGARY' => 'HU', 'SLOVAKIA' => 'SK', 'SLOVENIA' => 'SI',
        'PORTUGAL' => 'PT', 'TURKEY' => 'TR', 'TURKIYE' => 'TR', 'RUSSIA' => 'RU', 'SOUTH AFRICA' => 'ZA',
        'ARGENTINA' => 'AR', 'THAILAND' => 'TH', 'INDONESIA' => 'ID', 'MALAYSIA' => 'MY', 'VIETNAM' => 'VN',
        'TAIWAN' => 'TW', 'AUSTRALIA' => 'AU', 'UKRAINE' => 'UA', 'SERBIA' => 'RS', 'MOROCCO' => 'MA',
        'FINLAND' => 'FI',
    ];

    public static function toIso(?string $name): ?string
    {
        return self::MAP[strtoupper(trim((string) $name))] ?? null;
    }
}
