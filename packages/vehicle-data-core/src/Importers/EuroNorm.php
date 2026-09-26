<?php

declare(strict_types=1);

namespace VehicleData\Core\Importers;

final class EuroNorm
{
    /**
     * Stage letters per Regulation (EU) 2017/1151 Annex I: AG=6c, AM=6d-TEMP, AP/AX/AZ/AD=6d(-ISC-FCM),
     * EA/EB/EC=6e. Unknown → null, raw kept in specifications.euro_stage_raw.
     *
     * Digit→letter boundaries (e.g. "6AP") are split before matching, because \b does not see a
     * boundary between a digit and a letter (both are "word" characters) - without the split, a
     * stage code glued straight onto the "6" (as EEA's Ech column does) would never match AP/EA/etc.
     */
    public static function fromStage(?string $ech): ?string
    {
        $s = strtoupper(trim((string) $ech));
        if ($s === '') {
            return null;
        }
        $s = str_replace(['EURO', 'EU ', ' VI', 'VI'], ['', '', ' 6', '6'], $s);
        $s = preg_replace('/(\d)([A-Z])/u', '$1 $2', $s) ?? $s;
        $letters = preg_replace('/[^A-Z0-9]/', ' ', $s) ?? '';
        if (preg_match('/\b(EA|EB|EC)\b/', $letters) === 1 || str_contains($letters, '6E')) {
            return 'euro_6e';
        }
        if (preg_match('/\b(AM|AP|AX|AZ|AD)\b/', $letters) === 1 || preg_match('/6\s?D/', $letters) === 1) {
            return 'euro_6d';
        }
        foreach ([6, 5, 4, 3, 2, 1] as $n) {
            if (preg_match('/(^|[^0-9.])'.$n.'([^0-9]|$)/', $letters) === 1 && $n !== 1) {
                return 'euro_'.$n;
            }
        }

        return null;
    }
}
