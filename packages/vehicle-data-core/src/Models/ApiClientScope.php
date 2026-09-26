<?php

declare(strict_types=1);

namespace VehicleData\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $client_id
 * @property string $scope
 */
final class ApiClientScope extends Model
{
    protected $table = 'vd_api_client_scopes';

    protected $guarded = [];

    public $timestamps = false;

    /** @return BelongsTo<ApiClient, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class, 'client_id');
    }
}
