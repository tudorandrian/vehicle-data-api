<?php

declare(strict_types=1);

namespace VehicleData\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use VehicleData\Core\Database\Factories\VariantFactory;

/**
 * @property int $id
 * @property string $public_id
 * @property int $model_id
 * @property string $fuel_code
 * @property string $eu_category_code
 * @property string|null $euro_norm_code
 * @property int|null $engine_cc
 * @property int|null $power_kw
 * @property int|null $mass_kg
 * @property int|null $co2_wltp
 * @property int|null $year_from
 * @property int|null $year_to
 * @property array<string, mixed>|null $specifications
 */
final class Variant extends Model
{
    /** @use HasFactory<VariantFactory> */
    use HasFactory;

    protected $table = 'vd_variants';

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'specifications' => 'array',
    ];

    protected static function newFactory(): VariantFactory
    {
        return VariantFactory::new();
    }

    /** @return BelongsTo<VehicleModel, $this> */
    public function model(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'model_id');
    }

    /** @return MorphMany<RecordSource, $this> */
    public function sources(): MorphMany
    {
        return $this->morphMany(RecordSource::class, 'record');
    }
}
