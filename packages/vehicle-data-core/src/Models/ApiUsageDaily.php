<?php

declare(strict_types=1);

namespace VehicleData\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $client_id
 * @property Carbon $date
 * @property int $requests
 * @property int $errors
 * @property int $p95_ms
 */
final class ApiUsageDaily extends Model
{
    protected $table = 'vd_api_usage_daily';

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'date' => 'immutable_date',
    ];

    /** @return BelongsTo<ApiClient, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class, 'client_id');
    }
}
