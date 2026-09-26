<?php

declare(strict_types=1);

namespace VehicleData\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $record_type
 * @property int $record_id
 * @property int $source_id
 * @property string $source_ref
 * @property Carbon $retrieved_at
 * @property string $checksum
 */
final class RecordSource extends Model
{
    protected $table = 'vd_record_sources';

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'retrieved_at' => 'immutable_datetime',
    ];

    /** @return MorphTo<Model, $this> */
    public function record(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Source, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }
}
