<?php

declare(strict_types=1);

namespace VehicleData\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $request_id
 * @property int|null $client_id
 * @property string $route
 * @property string $method
 * @property int $status
 * @property int $duration_ms
 * @property int $bytes
 * @property string|null $ip
 * @property Carbon $created_at
 */
final class ApiRequest extends Model
{
    protected $table = 'vd_api_requests';

    protected $guarded = [];

    public $timestamps = false;

    /** @var array<string, string> */
    protected $casts = [
        'created_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<ApiClient, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class, 'client_id');
    }
}
