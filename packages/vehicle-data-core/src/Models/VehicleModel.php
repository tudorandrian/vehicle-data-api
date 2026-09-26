<?php

declare(strict_types=1);

namespace VehicleData\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use VehicleData\Core\Database\Factories\VehicleModelFactory;
use VehicleData\Core\Models\Concerns\HasPublicId;

/**
 * @property int $id
 * @property string $public_id
 * @property string $slug
 * @property int $make_id
 * @property string $name
 * @property int|null $first_year
 * @property int|null $last_year
 * @property int|null $ro_fleet_count
 * @property int|null $ro_fleet_year
 */
final class VehicleModel extends Model
{
    /** @use HasFactory<VehicleModelFactory> */
    use HasFactory;

    use HasPublicId;

    protected $table = 'vd_models';

    protected $guarded = [];

    protected static function newFactory(): VehicleModelFactory
    {
        return VehicleModelFactory::new();
    }

    /** @return BelongsTo<Make, $this> */
    public function make(): BelongsTo
    {
        return $this->belongsTo(Make::class, 'make_id');
    }

    /** @return HasMany<Variant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(Variant::class, 'model_id');
    }

    /** @return MorphMany<RecordSource, $this> */
    public function sources(): MorphMany
    {
        return $this->morphMany(RecordSource::class, 'record');
    }
}
