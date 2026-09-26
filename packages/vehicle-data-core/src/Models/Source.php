<?php

declare(strict_types=1);

namespace VehicleData\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string $licence_id
 * @property string $licence_name
 * @property string $licence_url
 * @property string $attribution
 * @property string $url
 * @property string|null $terms_url
 */
final class Source extends Model
{
    protected $table = 'vd_sources';

    protected $guarded = [];

    /** @return HasMany<RecordSource, $this> */
    public function recordSources(): HasMany
    {
        return $this->hasMany(RecordSource::class, 'source_id');
    }
}
