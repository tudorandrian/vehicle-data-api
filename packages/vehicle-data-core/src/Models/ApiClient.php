<?php

declare(strict_types=1);

namespace VehicleData\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use VehicleData\Core\Database\Factories\ApiClientFactory;

/**
 * @property int $id
 * @property string $name
 * @property string $owner
 * @property string $key_prefix
 * @property string $key_hash
 * @property array<int, string> $allowed_origins
 * @property int $rate_per_minute
 * @property int $daily_quota
 * @property Carbon|null $expires_at
 * @property Carbon|null $last_used_at
 * @property Carbon|null $disabled_at
 */
final class ApiClient extends Model
{
    /** @use HasFactory<ApiClientFactory> */
    use HasFactory;

    protected $table = 'vd_api_clients';

    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['key_hash'];

    /** @var array<string, string> */
    protected $casts = [
        'allowed_origins' => 'array',
        'expires_at' => 'immutable_datetime',
        'last_used_at' => 'immutable_datetime',
        'disabled_at' => 'immutable_datetime',
    ];

    protected static function newFactory(): ApiClientFactory
    {
        return ApiClientFactory::new();
    }

    /** @return HasMany<ApiClientScope, $this> */
    public function scopes(): HasMany
    {
        return $this->hasMany(ApiClientScope::class, 'client_id');
    }
}
