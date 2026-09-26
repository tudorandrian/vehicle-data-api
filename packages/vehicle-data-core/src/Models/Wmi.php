<?php

declare(strict_types=1);

namespace VehicleData\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @property int $id
 * @property string $code
 * @property string $manufacturer_name
 * @property string|null $country_code
 * @property string|null $vehicle_type
 * @property int $source_id
 */
final class Wmi extends Model
{
    protected $table = 'vd_wmi';

    protected $guarded = [];

    /** @return BelongsTo<Source, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /** @return MorphMany<RecordSource, $this> */
    public function sources(): MorphMany
    {
        return $this->morphMany(RecordSource::class, 'record');
    }
}
