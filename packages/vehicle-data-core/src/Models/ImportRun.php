<?php

declare(strict_types=1);

namespace VehicleData\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $source_id
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property string $status
 * @property int $rows_read
 * @property int $rows_written
 * @property int $rows_rejected
 * @property array<string, mixed>|null $reject_report
 * @property string|null $file_checksum
 * @property array<string, mixed>|null $options
 */
final class ImportRun extends Model
{
    protected $table = 'vd_import_runs';

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'reject_report' => 'array',
        'options' => 'array',
        'started_at' => 'immutable_datetime',
        'finished_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<Source, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }
}
