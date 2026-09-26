<?php

declare(strict_types=1);

namespace VehicleData\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use VehicleData\Core\Database\Factories\MakeFactory;
use VehicleData\Core\Models\Concerns\HasPublicId;

/**
 * @property int $id
 * @property string $public_id
 * @property string $slug
 * @property string $name
 * @property string $kind
 * @property int|null $manufacturer_id
 * @property int|null $ro_fleet_count
 * @property int|null $ro_fleet_year
 */
final class Make extends Model
{
    /** @use HasFactory<MakeFactory> */
    use HasFactory;

    use HasPublicId;

    protected $table = 'vd_makes';

    protected $guarded = [];

    protected static function newFactory(): MakeFactory
    {
        return MakeFactory::new();
    }

    /** @return BelongsTo<Manufacturer, $this> */
    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(Manufacturer::class);
    }

    /** @return HasMany<VehicleModel, $this> */
    public function models(): HasMany
    {
        return $this->hasMany(VehicleModel::class, 'make_id');
    }

    /** @return MorphMany<RecordSource, $this> */
    public function sources(): MorphMany
    {
        return $this->morphMany(RecordSource::class, 'record');
    }
}
