<?php

declare(strict_types=1);

namespace VehicleData\Core\Locale;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class LabelResolver
{
    /** @param list<string> $locales */
    public function __construct(private readonly array $locales, private readonly string $default) {}

    public function resolve(Request $request): string
    {
        $lang = $request->query('lang');
        if (is_string($lang) && $lang !== '') {
            if (! in_array($lang, $this->locales, true)) {
                throw ValidationException::withMessages(['lang' => ['Supported values: '.implode(', ', $this->locales).'.']]);
            }

            return $lang;
        }

        foreach ($request->getLanguages() as $candidate) {
            $short = strtolower(substr($candidate, 0, 2));
            if (in_array($short, $this->locales, true)) {
                return $short;
            }
        }

        return $this->default;
    }
}
