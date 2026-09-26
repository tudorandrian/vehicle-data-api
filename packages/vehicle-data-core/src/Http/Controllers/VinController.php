<?php

declare(strict_types=1);

namespace VehicleData\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use VehicleData\Core\Http\Problem\Problem;
use VehicleData\Core\Http\Query\Envelope;
use VehicleData\Core\Http\Query\Format;
use VehicleData\Core\Http\Query\Provenance;
use VehicleData\Core\Locale\LabelResolver;
use VehicleData\Core\Models\Wmi;
use VehicleData\Core\Vin\InvalidVin;
use VehicleData\Core\Vin\VinDecoder;

final class VinController
{
    public function __construct(private readonly LabelResolver $labels) {}

    public function show(Request $request, string $vin, VinDecoder $decoder): JsonResponse
    {
        // Negotiate representation and language before touching the decoder/DB
        // (same ordering rationale as Envelope::list — don't pay for work a 406/422
        // can't use).
        Format::negotiate($request, false);
        $lang = $this->labels->resolve($request);

        try {
            $result = $decoder->decode($vin);
        } catch (InvalidVin $e) {
            // A path segment that cannot be a VIN is a bad request (400), not an
            // invalid query parameter (422).
            return Problem::response(400, 'Bad Request', $e->getMessage(), [], '/problems/malformed-vin');
        }

        $wmi = Wmi::query()->where('code', $result->wmi)->first();
        $sources = $wmi === null ? [] : Provenance::for($wmi);

        return Envelope::item($result->toArray(), $sources, $lang);
    }
}
