<?php

declare(strict_types=1);

namespace VehicleData\Core\Http\Query;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotAcceptableHttpException;

final class Format
{
    /** @return 'json'|'csv' */
    public static function negotiate(Request $request, bool $csvAllowed): string
    {
        $q = $request->query('format');
        if ($q === 'csv') {
            if (! $csvAllowed) {
                throw new NotAcceptableHttpException('CSV is available on list routes only.');
            }

            return 'csv';
        }
        $accept = (string) $request->headers->get('Accept', '*/*');
        if ($accept === '' || str_contains($accept, '*/*') || str_contains($accept, 'application/json') || str_contains($accept, 'application/problem+json')) {
            return 'json';
        }
        if ($csvAllowed && str_contains($accept, 'text/csv')) {
            return 'csv';
        }
        throw new NotAcceptableHttpException('Supported representations: application/json'.($csvAllowed ? ', text/csv' : '').'.');
    }
}
