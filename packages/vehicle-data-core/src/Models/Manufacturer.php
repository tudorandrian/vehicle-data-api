<?php

declare(strict_types=1);

namespace VehicleData\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use VehicleData\Core\Database\Factories\ManufacturerFactory;
use VehicleData\Core\Models\Concerns\HasPublicId;

/**
 * @property int $id
 * @property string $public_id
 * @property string $slug
 * @property string $name
 * @property string|null $country_code
 * @property int|null $founded_year
 * @property string|null $parent_slug
 * @property string|null $website
 * @property string $wikidata_qid
 * @property string|null $parent_qid
 * @property string|null $logo_commons_file
 * @property string|null $logo_licence
 */
final class Manufacturer extends Model
{
    /** @use HasFactory<ManufacturerFactory> */
    use HasFactory;

    use HasPublicId;

    protected $table = 'vd_manufacturers';

    protected $guarded = [];

    protected static function newFactory(): ManufacturerFactory
    {
        return ManufacturerFactory::new();
    }

    /** @return HasMany<Make, $this> */
    public function makes(): HasMany
    {
        return $this->hasMany(Make::class, 'manufacturer_id');
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_slug', 'slug');
    }

    /** @return MorphMany<RecordSource, $this> */
    public function sources(): MorphMany
    {
        return $this->morphMany(RecordSource::class, 'record');
    }
}
