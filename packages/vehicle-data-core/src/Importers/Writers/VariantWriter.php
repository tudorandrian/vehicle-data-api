<?php

declare(strict_types=1);

namespace VehicleData\Core\Importers\Writers;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use VehicleData\Core\Contracts\RecordWriter;
use VehicleData\Core\Importers\CatalogueIdentity;
use VehicleData\Core\Importers\DomainRow;
use VehicleData\Core\Kinds\KindRegistry;
use VehicleData\Core\Kinds\SchemaValidator;
use VehicleData\Core\Models\Source;
use VehicleData\Core\Models\Variant;
use VehicleData\Core\Support\PublicId;

final class VariantWriter implements RecordWriter
{
    public function type(): string
    {
        return 'variant';
    }

    /** @return list<Model> */
    public function write(DomainRow $row, Source $source, \DateTimeInterface $retrievedAt): array
    {
        $a = $row->attributes;
        $spec = $a['specifications'] ?? null;
        if ($spec !== null) {
            $errors = SchemaValidator::errors($spec, app(KindRegistry::class)->schema('car')->jsonSchema());
            if ($errors !== []) {
                throw new RuntimeException('Invalid car specifications for '.$row->sourceRef.': '.implode('; ', $errors));
            }
        }

        $make = CatalogueIdentity::make($source, $a['make_raw'], $a['make_name'], 'car', $retrievedAt);
        $model = CatalogueIdentity::model($source, $make, $a['model_raw'], $a['model_name'], $retrievedAt);

        // A make/model rename must not duplicate an existing variant, but a genuinely new variant
        // still gets its deterministic id so fixtures produce the same ids on every machine. The match
        // is deliberately by physical identity within the model — not by source or natural_key — so the
        // same physical variant reported under a different raw make/model spelling (or a different
        // source entirely) still resolves to the one row; ->orderBy('id') keeps the pick deterministic
        // if more than one historical row ever matches.
        $existing = Variant::query()->where('model_id', $model->id)->where('fuel_code', $a['fuel_code'])->where('eu_category_code', $a['eu_category_code'])
            ->where('euro_norm_code', $a['euro_norm_code'] ?? null)->where('engine_cc', $a['engine_cc'] ?? null)->where('power_kw', $a['power_kw'] ?? null)
            ->orderBy('id')->first();
        $publicId = $existing !== null ? $existing->public_id : PublicId::for($a['natural_key']);
        // Re-imports of the same variant across years widen the range instead of overwriting it:
        // year_from = min(existing, new), year_to = max(existing, new), ignoring whichever side is null.
        $yearFrom = self::merge($existing?->year_from, $a['year_from'] ?? null, fn (int $x, int $y) => min($x, $y));
        $yearTo = self::merge($existing?->year_to, $a['year_to'] ?? null, fn (int $x, int $y) => max($x, $y));

        $variant = Variant::query()->updateOrCreate(['public_id' => $publicId], [
            'model_id' => $model->id, 'fuel_code' => $a['fuel_code'], 'eu_category_code' => $a['eu_category_code'], 'euro_norm_code' => $a['euro_norm_code'] ?? null,
            'engine_cc' => $a['engine_cc'] ?? null, 'power_kw' => $a['power_kw'] ?? null, 'mass_kg' => $a['mass_kg'] ?? null, 'co2_wltp' => $a['co2_wltp'] ?? null,
            'year_from' => $yearFrom, 'year_to' => $yearTo, 'specifications' => $spec,
        ]);
        if ($yearFrom !== null) {
            $model->first_year = $model->first_year === null ? $yearFrom : min($model->first_year, $yearFrom);
            $model->saveQuietly();
        }

        return [$make, $model, $variant];
    }

    /** @param callable(int,int):int $combine */
    private static function merge(?int $existing, ?int $new, callable $combine): ?int
    {
        if ($existing === null) {
            return $new;
        }
        if ($new === null) {
            return $existing;
        }

        return $combine($existing, $new);
    }
}
