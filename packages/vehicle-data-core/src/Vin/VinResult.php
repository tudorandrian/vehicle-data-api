<?php

declare(strict_types=1);

namespace VehicleData\Core\Vin;

final readonly class VinResult
{
    /**
     * @param  array{name: string, country_code: ?string}|null  $manufacturer
     * @param  array{applies: bool, expected: string, actual: string, valid: bool}  $checkDigit
     * @param  array{character: string, candidates: list<int>, resolved: ?int}  $modelYear
     */
    public function __construct(
        public string $vin,
        public string $wmi,
        public string $vds,
        public string $vis,
        public string $region,
        public ?array $manufacturer,
        public array $checkDigit,
        public array $modelYear,
        public string $plantCode,
        public string $serial,
        public string $confidence,
        public string $note,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'vin' => $this->vin,
            'wmi' => $this->wmi,
            'vds' => $this->vds,
            'vis' => $this->vis,
            'region' => $this->region,
            'manufacturer' => $this->manufacturer,
            'check_digit' => $this->checkDigit,
            'model_year' => $this->modelYear,
            'plant_code' => $this->plantCode,
            'serial' => $this->serial,
            'confidence' => $this->confidence,
            'note' => $this->note,
        ];
    }
}
