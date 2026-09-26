<?php

declare(strict_types=1);

namespace VehicleData\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use VehicleData\Core\Models\Concerns\HasPublicId;

/**
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $description
 */
final class Taxonomy extends Model
{
    use HasPublicId;

    protected $table = 'vd_taxonomies';

    protected $guarded = [];

    /** @return HasMany<TaxonomyTerm, $this> */
    public function terms(): HasMany
    {
        return $this->hasMany(TaxonomyTerm::class)->orderBy('sort_order');
    }
}
